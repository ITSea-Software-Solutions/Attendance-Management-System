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
 * TrueCrew native channel: USB-OTG fingerprint capture — multi-vendor.
 *
 * Vendor SDKs are integrated via REFLECTION so the app compiles and ships
 * without them; drop the jar into android/app/libs/ and rebuild to enable
 * real captures for that brand:
 *   - SecuGen (FDxSDKProFDAndroid.jar) — capture + GetMatchingScore
 *   - Mantra MFS100 (mantra.mfs100.jar) — AutoCapture + MatchISO
 * Everything else is DETECTED and explained (Aadhaar L1 devices such as the
 * Mantra MFS110 encrypt fingerprints inside the sensor and can never expose
 * templates to apps — that's UIDAI's design, not a missing driver).
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

    private fun mantraSdkPresent(): Boolean = try {
        Class.forName("com.mantra.mfs100.MFS100"); true
    } catch (e: Throwable) { false }

    /** Brand classifier — by product/manufacturer strings first, VID as hint. */
    private fun brandOf(d: UsbDevice): String {
        val name = "${d.productName ?: ""} ${d.manufacturerName ?: ""}".lowercase()
        return when {
            d.vendorId == secugenVendorId || name.contains("secugen") -> "secugen"
            name.contains("mfs110") || name.contains("mantra l1") -> "mantra_l1"
            name.contains("mantra") || name.contains("mfs100") || name.contains("mfs") -> "mantra"
            name.contains("morpho") || name.contains("idemia") || name.contains("mso") -> "morpho"
            name.contains("startek") || name.contains("fm220") -> "startek"
            name.contains("tatvik") || name.contains("tmf") -> "tatvik"
            name.contains("precision") || name.contains("pb510") -> "precision"
            name.contains("evolute") -> "evolute"
            name.contains("next biometrics") || name.contains("nb-") -> "next"
            else -> "unknown"
        }
    }

    private fun findMantra(): UsbDevice? =
        usbManager().deviceList.values.firstOrNull { brandOf(it) == "mantra" }

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
                    "matchScore" -> {
                        val t1 = call.argument<String>("t1")
                        val t2 = call.argument<String>("t2")
                        Thread { matchReflect(t1, t2, result) }.start()
                    }
                    "usbInventory" -> result.success(usbInventory())
                    "mantraStatus" -> result.success(mantraStatusMap())
                    "mantraCapture" -> {
                        val timeout = (call.argument<Int>("timeoutMs") ?: 10000)
                        Thread { mantraCapture(timeout, result) }.start()
                    }
                    "mantraMatch" -> {
                        val t1 = call.argument<String>("t1")
                        val t2 = call.argument<String>("t2")
                        Thread { mantraMatch(t1, t2, result) }.start()
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
        // Any supported scanner: SecuGen first (native path), then Mantra.
        val dev = findScanner() ?: findMantra()
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

    // ─── Multi-vendor: inventory + Mantra MFS100 (reflection) ────────────────

    /** Every attached USB device with its classified brand — diagnostics. */
    private fun usbInventory(): List<Map<String, Any?>> =
        usbManager().deviceList.values.map { d ->
            mapOf(
                "name" to (d.productName ?: "USB device"),
                "manufacturer" to d.manufacturerName,
                "vendorId" to d.vendorId,
                "productId" to d.productId,
                "brand" to brandOf(d),
                "permission" to usbManager().hasPermission(d)
            )
        }

    private fun mantraStatusMap(): Map<String, Any?> {
        val dev = findMantra()
        return mapOf(
            "deviceAttached" to (dev != null),
            "deviceName" to dev?.productName,
            "permission" to (dev != null && usbManager().hasPermission(dev)),
            "sdkPresent" to mantraSdkPresent()
        )
    }

    /**
     * Build an MFS100 instance with a no-op event proxy. The SDK requires an
     * MFS100Event listener even for synchronous AutoCapture use.
     */
    private fun mantraLib(): Pair<Class<*>, Any> {
        val libCls = Class.forName("com.mantra.mfs100.MFS100")
        val evCls = Class.forName("com.mantra.mfs100.MFS100Event")
        val handler = java.lang.reflect.InvocationHandler { _, method, _ ->
            // OnDeviceAttached/OnPreview/etc — nothing to do; primitives need defaults
            when (method.returnType) {
                java.lang.Boolean.TYPE -> false
                java.lang.Integer.TYPE -> 0
                else -> null
            }
        }
        val event = java.lang.reflect.Proxy.newProxyInstance(evCls.classLoader, arrayOf(evCls), handler)
        val lib = try {
            libCls.getConstructor(evCls).newInstance(event)
        } catch (_: NoSuchMethodException) {
            libCls.getConstructor(Context::class.java, evCls).newInstance(this, event)
        }
        try {
            libCls.getMethod("SetApplicationContext", Context::class.java).invoke(lib, this)
        } catch (_: Throwable) { /* older SDKs have no context setter */ }
        return libCls to lib
    }

    private fun mantraInit(libCls: Class<*>, lib: Any): Int {
        var rc = (libCls.getMethod("Init").invoke(lib) as Number).toInt()
        if (rc != 0) {
            // Some firmwares need an explicit load after first init
            try {
                rc = (libCls.getMethod("LoadFirmware").invoke(lib) as Number).toInt()
                if (rc == 0) rc = (libCls.getMethod("Init").invoke(lib) as Number).toInt()
            } catch (_: Throwable) { /* no LoadFirmware in this SDK */ }
        }
        return rc
    }

    /** Capture via reflected MFS100.AutoCapture → ISO 19794-2 template. */
    private fun mantraCapture(timeoutMs: Int, result: MethodChannel.Result) {
        fun fail(msg: String) = runOnUiThread { result.success(mapOf("error" to msg)) }
        val dev = findMantra() ?: return fail("no-device")
        if (!usbManager().hasPermission(dev)) return fail("no-permission")
        if (!mantraSdkPresent()) return fail("no-sdk")
        try {
            val (libCls, lib) = mantraLib()
            val rc = mantraInit(libCls, lib)
            if (rc != 0) return fail("Mantra Init failed (code $rc) — replug the scanner")
            val fdCls = Class.forName("com.mantra.mfs100.FingerData")
            val fd = fdCls.getDeclaredConstructor().newInstance()
            val intT = java.lang.Integer.TYPE
            val boolT = java.lang.Boolean.TYPE
            // Signature varies across SDK versions — try the known shapes.
            val cap = try {
                (libCls.getMethod("AutoCapture", fdCls, intT, boolT)
                    .invoke(lib, fd, timeoutMs, false) as Number).toInt()
            } catch (_: NoSuchMethodException) {
                (libCls.getMethod("AutoCapture", fdCls, intT, boolT, boolT)
                    .invoke(lib, fd, timeoutMs, false, false) as Number).toInt()
            }
            if (cap != 0) {
                try { libCls.getMethod("Uninit").invoke(lib) } catch (_: Throwable) {}
                return fail(if (cap == -1140) "No finger detected — place a finger and retry."
                            else "Capture failed (Mantra code $cap)")
            }
            fun fdGet(name: String): Any? = try {
                fdCls.getMethod(name).invoke(fd)
            } catch (_: NoSuchMethodException) {
                try { fdCls.getField(name).get(fd) } catch (_: Throwable) { null }
            }
            val iso = fdGet("ISOTemplate") as? ByteArray
                ?: run {
                    try { libCls.getMethod("Uninit").invoke(lib) } catch (_: Throwable) {}
                    return fail("SDK returned no ISO template")
                }
            val quality = (fdGet("Quality") as? Number)?.toInt() ?: 0
            try { libCls.getMethod("Uninit").invoke(lib) } catch (_: Throwable) {}
            val b64 = Base64.encodeToString(iso, Base64.NO_WRAP)
            runOnUiThread { result.success(mapOf("template" to b64, "quality" to quality)) }
        } catch (e: Throwable) {
            fail("Mantra reflection error: ${e.javaClass.simpleName} ${e.message ?: ""} — check bundled MFS100 SDK version")
        }
    }

    /** 1:1 ISO match via reflected MFS100.MatchISO — raw score 0–100000. */
    private fun mantraMatch(t1b64: String?, t2b64: String?, result: MethodChannel.Result) {
        fun reply(map: Map<String, Any?>) = runOnUiThread { result.success(map) }
        if (t1b64 == null || t2b64 == null) { reply(mapOf("error" to "missing templates")); return }
        if (!mantraSdkPresent()) { reply(mapOf("error" to "no-sdk")); return }
        try {
            val (libCls, lib) = mantraLib()
            val t1 = Base64.decode(t1b64, Base64.DEFAULT)
            val t2 = Base64.decode(t2b64, Base64.DEFAULT)
            val raw = (libCls.getMethod("MatchISO", ByteArray::class.java, ByteArray::class.java)
                .invoke(lib, t1, t2) as Number).toInt()
            try { libCls.getMethod("Uninit").invoke(lib) } catch (_: Throwable) {}
            reply(mapOf("raw" to raw))
        } catch (e: Throwable) {
            reply(mapOf("error" to "Mantra match reflection: ${e.javaClass.simpleName}"))
        }
    }

    /** 1:1 match score via reflected JSGFPLib.GetMatchingScore (0–199). */
    private fun matchReflect(t1b64: String?, t2b64: String?, result: MethodChannel.Result) {
        fun reply(map: Map<String, Any?>) = runOnUiThread { result.success(map) }
        if (t1b64 == null || t2b64 == null) { reply(mapOf("error" to "missing templates")); return }
        if (!sdkPresent()) { reply(mapOf("error" to "no-sdk")); return }
        try {
            val libCls = Class.forName("SecuGen.FDxSDKPro.JSGFPLib")
            val lib = try {
                libCls.getConstructor(android.content.Context::class.java, UsbManager::class.java)
                    .newInstance(this, usbManager())
            } catch (_: NoSuchMethodException) {
                libCls.getConstructor(UsbManager::class.java).newInstance(usbManager())
            }
            (libCls.getMethod("Init", java.lang.Long.TYPE).invoke(lib, 255L) as Number).toLong()
            // ISO templates (same format both sides — enrollment + gate).
            val fmtCls = Class.forName("SecuGen.FDxSDKPro.SGFDxTemplateFormat")
            val iso = (fmtCls.getField("TEMPLATE_FORMAT_ISO19794").get(null) as Number).toShort()
            libCls.getMethod("SetTemplateFormat", java.lang.Short.TYPE).invoke(lib, iso)
            val t1 = Base64.decode(t1b64, Base64.DEFAULT)
            val t2 = Base64.decode(t2b64, Base64.DEFAULT)
            val score = IntArray(1)
            val rc = (libCls.getMethod("GetMatchingScore",
                    ByteArray::class.java, ByteArray::class.java, IntArray::class.java)
                .invoke(lib, t1, t2, score) as Number).toLong()
            if (rc != 0L) { reply(mapOf("error" to "match rc $rc")); return }
            reply(mapOf("score" to score[0]))
        } catch (e: Throwable) {
            reply(mapOf("error" to "match reflection: ${e.javaClass.simpleName}"))
        }
    }
}
