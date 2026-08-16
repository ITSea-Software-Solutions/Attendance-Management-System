package com.itsea.ams_client

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbManager
import android.os.Build
import android.util.Base64
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * TrueCrew native channel: SecuGen USB-OTG fingerprint capture.
 *
 * SecuGen's Android SDK (FDxSDKProFDAndroid.jar + jniLibs) is bundled in app/libs.
 * We integrate it via reflection so this app compiles and ships without the
 * AAR; drop the file into android/app/libs/ and rebuild to enable REAL
 * captures. Until then the channel reports precise status:
 *   - whether a SecuGen device (USB vendor 0x1162) is attached,
 *   - whether USB permission is granted,
 *   - whether the SDK AAR is present.
 */
class MainActivity : FlutterActivity() {
    private val channelName = "truecrew/sgfp"
    private val actionUsb = "com.itsea.ams_client.USB_PERMISSION"
    private val secugenVendorId = 0x1162 // 4450

    private fun usbManager(): UsbManager = getSystemService(Context.USB_SERVICE) as UsbManager

    private fun findScanner(): UsbDevice? =
        usbManager().deviceList.values.firstOrNull { it.vendorId == secugenVendorId }

    private fun sdkPresent(): Boolean = try {
        Class.forName("SecuGen.FDxSDKPro.JSGFPLib"); true
    } catch (e: Throwable) { false }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "status" -> result.success(statusMap())
                    "requestPermission" -> requestPermission(result)
                    "capture" -> {
                        val timeout = (call.argument<Int>("timeoutMs") ?: 10000)
                        Thread { captureReflect(timeout, result) }.start()
                    }
                    else -> result.notImplemented()
                }
            }
    }

    private fun statusMap(): Map<String, Any?> {
        val dev = findScanner()
        return mapOf(
            "deviceAttached" to (dev != null),
            "deviceName" to dev?.productName,
            "vendorId" to dev?.vendorId,
            "permission" to (dev != null && usbManager().hasPermission(dev)),
            "sdkPresent" to sdkPresent()
        )
    }

    private fun requestPermission(result: MethodChannel.Result) {
        val dev = findScanner()
        if (dev == null) { result.success(false); return }
        if (usbManager().hasPermission(dev)) { result.success(true); return }
        var replied = false
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context?, i: Intent?) {
                try { unregisterReceiver(this) } catch (_: Throwable) {}
                if (!replied) { replied = true
                    result.success(i?.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false) ?: false)
                }
            }
        }
        val flags = if (Build.VERSION.SDK_INT >= 33) Context.RECEIVER_NOT_EXPORTED else 0
        if (Build.VERSION.SDK_INT >= 33)
            registerReceiver(receiver, IntentFilter(actionUsb), flags)
        else
            @Suppress("UnspecifiedRegisterReceiverFlag")
            registerReceiver(receiver, IntentFilter(actionUsb))
        // Android 14+: a MUTABLE PendingIntent must wrap an EXPLICIT intent —
        // without setPackage the system rejects it and the permission dialog
        // silently never appears.
        val pi = PendingIntent.getBroadcast(
            this, 0, Intent(actionUsb).setPackage(packageName),
            if (Build.VERSION.SDK_INT >= 31) PendingIntent.FLAG_MUTABLE else 0
        )
        try {
            usbManager().requestPermission(dev, pi)
        } catch (e: Throwable) {
            try { unregisterReceiver(receiver) } catch (_: Throwable) {}
            if (!replied) { replied = true; result.success(false) }
        }
    }

    /** Full capture via reflected JSGFPLib: Init(AUTO) → Open → ISO template. */
    private fun captureReflect(timeoutMs: Int, result: MethodChannel.Result) {
        fun fail(msg: String) = runOnUiThread { result.success(mapOf("error" to msg)) }
        val dev = findScanner() ?: return fail("no-device")
        if (!usbManager().hasPermission(dev)) return fail("no-permission")
        if (!sdkPresent()) return fail("no-sdk")
        try {
            val libCls = Class.forName("SecuGen.FDxSDKPro.JSGFPLib")
            // SDK v4.2x: JSGFPLib(Context, UsbManager); older: JSGFPLib(UsbManager)
            val lib = try {
                libCls.getConstructor(android.content.Context::class.java, UsbManager::class.java)
                    .newInstance(this, usbManager())
            } catch (_: NoSuchMethodException) {
                libCls.getConstructor(UsbManager::class.java).newInstance(usbManager())
            }
            val longT = java.lang.Long.TYPE
            fun call(name: String, vararg pairs: Pair<Class<*>, Any?>): Long {
                val m = libCls.getMethod(name, *pairs.map { it.first }.toTypedArray())
                return (m.invoke(lib, *pairs.map { it.second }.toTypedArray()) as Number).toLong()
            }
            var rc = call("Init", longT to 255L) // SG_DEV_AUTO
            if (rc != 0L) return fail("Init failed (code $rc)")
            rc = call("OpenDevice", longT to 0L)
            if (rc != 0L) return fail("OpenDevice failed (code $rc) — reconnect the scanner")

            // Device info → image dimensions
            val infoCls = Class.forName("SecuGen.FDxSDKPro.SGDeviceInfoParam")
            val info = infoCls.getDeclaredConstructor().newInstance()
            call("GetDeviceInfo", infoCls to info)
            val w = (infoCls.getField("imageWidth").get(info) as Number).toInt().let { if (it > 0) it else 260 }
            val h = (infoCls.getField("imageHeight").get(info) as Number).toInt().let { if (it > 0) it else 300 }

            // ISO 19794-2 template format (SGFDxTemplateFormat.TEMPLATE_FORMAT_ISO19794)
            val fmtCls = Class.forName("SecuGen.FDxSDKPro.SGFDxTemplateFormat")
            val iso = (fmtCls.getField("TEMPLATE_FORMAT_ISO19794").get(null) as Number).toShort()
            libCls.getMethod("SetTemplateFormat", java.lang.Short.TYPE).invoke(lib, iso)

            val img = ByteArray(w * h)
            rc = (libCls.getMethod("GetImageEx", ByteArray::class.java, longT, longT)
                .invoke(lib, img, timeoutMs.toLong(), 50L) as Number).toLong()
            if (rc != 0L) {
                call("CloseDevice"); return fail(if (rc == 54L) "No finger detected — place a finger and retry." else "Capture failed (code $rc)")
            }
            val q = IntArray(1)
            libCls.getMethod("GetImageQuality", longT, longT, ByteArray::class.java, IntArray::class.java)
                .invoke(lib, w.toLong(), h.toLong(), img, q)

            val maxSize = IntArray(1)
            libCls.getMethod("GetMaxTemplateSize", IntArray::class.java).invoke(lib, maxSize)
            val tpl = ByteArray(if (maxSize[0] > 0) maxSize[0] else 1024)
            val fiCls = Class.forName("SecuGen.FDxSDKPro.SGFingerInfo")
            val fi = fiCls.getDeclaredConstructor().newInstance()
            fiCls.getField("FingerNumber").setInt(fi, 0)
            fiCls.getField("ImageQuality").setInt(fi, q[0])
            rc = (libCls.getMethod("CreateTemplate", fiCls, ByteArray::class.java, ByteArray::class.java)
                .invoke(lib, fi, img, tpl) as Number).toLong()
            if (rc != 0L) { call("CloseDevice"); return fail("CreateTemplate failed (code $rc)") }
            val sz = IntArray(1)
            try {
                libCls.getMethod("GetTemplateSize", ByteArray::class.java, IntArray::class.java).invoke(lib, tpl, sz)
            } catch (_: Throwable) { sz[0] = tpl.size }
            val size = if (sz[0] in 1..tpl.size) sz[0] else tpl.size
            call("CloseDevice")
            val b64 = Base64.encodeToString(tpl.copyOf(size), Base64.NO_WRAP)
            runOnUiThread { result.success(mapOf("template" to b64, "quality" to q[0])) }
        } catch (e: Throwable) {
            fail("SDK reflection error: ${e.javaClass.simpleName} ${e.message ?: ""} — check bundled SecuGen SDK version")
        }
    }
}
