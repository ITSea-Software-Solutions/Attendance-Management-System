import 'dart:io';

import 'package:flutter/services.dart';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:dio/io.dart';

import '../core/config.dart';
import 'sgfplib_ffi.dart';

/// SecuGen device error codes → human messages (mirror of the web map).
const Map<int, String> kSgiErrors = {
  51: 'Capture failed — try again',
  52: 'Memory failure',
  53: 'Device not found — check USB connection',
  54: 'No finger detected — place finger and try again',
  55: 'Device busy — wait and retry',
  56: 'Poor image quality — clean the sensor',
  57: 'Capture failed — try again',
  63: 'Service not responding',
  10001: 'License error (licstr) — service rejected the request',
  10004: 'No finger detected — click Scan then place your finger immediately',
};

/// Result of a device probe (diagnostics screen).
class DeviceProbe {
  final bool ok;
  final int? latencyMs;
  final String detail;
  DeviceProbe(this.ok, this.detail, {this.latencyMs});
}

/// Result of an enrollment capture (registration flow).
class EnrollCapture {
  final String template;
  final int quality;
  final bool simulated;
  EnrollCapture(this.template, this.quality, {required this.simulated});
}

/// Result of a capture+identify pass at the gate.
class IdentifyResult {
  final Map<String, Object?> worker;
  final int score;
  final bool simulated;
  IdentifyResult(this.worker, this.score, {this.simulated = false});
}

/// Biometric capture abstraction.
///
/// v0.9 drivers:
///  - [SimDriver]: hardware-free simulation (mirrors the server demo) — picks
///    a random deployed worker with a plausible score. Default everywhere.
///  - [SgibiosrvDriver]: Windows desktop with SecuGen's SGIBIOSRV service on
///    https://localhost:8443 — REAL capture; identification against the local
///    cache still requires the SDK matcher (next release), so after a real
///    capture we fall back to server-side identify when online.
///
/// The Android USB-OTG SecuGen SDK driver is planned (platform channel);
/// see CLIENT_APP_DESIGN.md "Biometric & verification support matrix".
abstract class BiometricDriver {
  Future<bool> available();
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates);

  /// Gate threshold — same scale + cutoff as the web gate (score 0–199).
  static const int matchThreshold = 40;

  /// 1:N ambiguity margin: SecuGen's 40 cutoff is calibrated for a SINGLE
  /// comparison; matching against N workers multiplies the false-accept
  /// chance. If the best and second-best DIFFERENT workers score within this
  /// margin, the identification is not trustworthy — treat as no-match and
  /// rescan rather than pick whoever edged ahead.
  static const int matchMargin = 10;

  /// All valid enrolled templates of a candidate — primary + backup finger.
  /// ANY of them identifies the worker; loops take the worker's best score,
  /// so one score per WORKER feeds the cross-worker ambiguity margin (a
  /// worker's own two fingers never look like two different people).
  static List<String> templatesOf(Map<String, Object?> w) {
    final out = <String>[];
    for (final k in [
      'fingerprint_template',
      'fingerprint_template_2',
      'fingerprint_template_3',
    ]) {
      final t = w[k] as String?;
      if (t != null && t.length >= 80 && !t.startsWith('U0lNRk1EOg')) out.add(t);
    }
    return out;
  }

  static Future<BiometricDriver> best() async {
    if (Platform.isWindows) {
      final direct = SgfpDirectDriver();
      if (await direct.available()) return direct;
      final sg = SgibiosrvDriver();
      if (await sg.available()) return sg;
    }
    if (Platform.isAndroid) {
      final droid = AndroidSgDriver();
      if (await droid.available()) return droid;
      final mantra = MantraAndroidDriver();
      if (await mantra.available()) return mantra;
    }
    return SimDriver();
  }

  static const MethodChannel _android = MethodChannel('truecrew/sgfp');

  /// Diagnostics: probe the fingerprint scanner with timing + detail.
  /// Windows: 1) DIRECT SDK via sgfplib.dll, 2) WebAPI, 3) report both.
  /// Android: USB device presence + permission + SDK-AAR presence, precisely.
  /// One human line per attached USB device, with brand-specific guidance —
  /// so any Indian market scanner plugged in gets a precise answer.
  static Future<String> usbInventoryText() async {
    try {
      final list = List<Map>.from(
          await _android.invokeMethod('usbInventory') as List);
      if (list.isEmpty) return '';
      final mantraSt = Map<String, dynamic>.from(
          await _android.invokeMethod('mantraStatus') as Map);
      final lines = <String>['USB devices:'];
      for (final d in list) {
        final name = '${d['name'] ?? 'device'}';
        switch ('${d['brand']}') {
          case 'secugen':
            lines.add('- $name (SecuGen): supported — native driver.');
            break;
          case 'mantra':
            lines.add(mantraSt['sdkPresent'] == true
                ? '- $name (Mantra MFS100-class): supported — driver active.'
                : '- $name (Mantra MFS100-class): driver built-in, but the Mantra SDK is not bundled in this build yet — it activates in the official APK once added.');
            break;
          case 'mantra_l1':
            lines.add('- $name (Mantra L1, e.g. MFS110): Aadhaar-secure device — it encrypts fingerprints INSIDE the sensor and cannot share them with any app (UIDAI rule, not a missing driver). Use an MFS100/MFS500 or SecuGen HP20 for attendance.');
            break;
          case 'morpho':
            lines.add('- $name (Morpho/IDEMIA): detected. Their SDK is licence-gated; support can be added when you have it. L1 units are Aadhaar-only.');
            break;
          case 'startek':
            lines.add('- $name (Startek FM220-class): detected. Support planned — share the ACPL SDK to activate.');
            break;
          default:
            lines.add('- $name: detected (unrecognised brand).');
        }
      }
      return lines.join('\n');
    } catch (_) {
      return '';
    }
  }

  static Future<DeviceProbe> probeScanner() async {
    if (Platform.isAndroid) {
      try {
        final st = Map<String, dynamic>.from(
            await _android.invokeMethod('status') as Map);
        final attached = st['deviceAttached'] == true;
        final perm = st['permission'] == true;
        final sdk = st['sdkPresent'] == true;
        final inventory = await usbInventoryText();
        if (!attached) {
          // No SecuGen — maybe a Mantra (or another Indian brand) is plugged in.
          final mantra = MantraAndroidDriver();
          if (await mantra.available()) {
            return DeviceProbe(true,
                'Mantra MFS100-class scanner ready via USB-OTG — real captures enabled.\n$inventory');
          }
          return DeviceProbe(false,
              inventory.isNotEmpty
                  ? 'No usable fingerprint scanner.\n$inventory\n(Fingerprint falls back to SIMULATION; camera Face attendance works without any scanner.)'
                  : 'No scanner on USB. Plug it in via an OTG cable/adapter — the phone must supply power. (Fingerprint falls back to SIMULATION; camera Face attendance works without any scanner.)');
        }
        final name = st['deviceName'] ?? 'SecuGen device';
        if (!sdk) {
          return DeviceProbe(false,
              'Scanner DETECTED on USB ($name) — but the SecuGen SDK library (FDxSDKProFDAndroid.jar) is missing from this build. Use the official TrueCrew APK from the download page (the SDK ships inside it).');
        }
        if (!perm) {
          return DeviceProbe(false,
              '$name detected + SDK present — USB permission not granted yet. Tap Test Capture and accept the Android USB prompt.');
        }
        return DeviceProbe(true,
            '$name ready via USB-OTG (SDK present, permission granted) — real captures enabled.');
      } catch (e) {
        return DeviceProbe(false, 'Native channel error: $e');
      }
    }
    if (!Platform.isWindows) {
      return DeviceProbe(false,
          'No USB scanner driver on this platform — fingerprint runs in SIMULATION.');
    }
    // 1) Direct SDK
    final swd = Stopwatch()..start();
    final direct = SgfpDirect.instance.probe();
    swd.stop();
    if (direct.$1) {
      return DeviceProbe(true, direct.$2, latencyMs: swd.elapsedMilliseconds);
    }
    final directWhy = direct.$2;
    // 2) WebAPI fallback
    final sw = Stopwatch()..start();
    final sg = SgibiosrvDriver();
    try {
      final r = await sg.rawCapturePing();
      sw.stop();
      final code = r.$1;
      final body = r.$2;
      // The service is ALIVE if it answers with SecuGen's JSON shape —
      // regardless of HTTP status (some builds use non-200 for device errors).
      final hasErrorCode = body.contains('ErrorCode');
      if (code == 200 || hasErrorCode) {
        String extra = '';
        final m = RegExp(r'"ErrorCode"\s*:\s*(\d+)').firstMatch(body);
        if (m != null) {
          final ec = int.parse(m.group(1)!);
          if (ec != 0) extra = '\nDevice says: ${kSgiErrors[ec] ?? "ErrorCode $ec"}';
        }
        return DeviceProbe(true,
            'Direct SDK unavailable ($directWhy)\nFallback OK: SGIBIOSRV WebAPI at ${AppConfig.sgibiosrvUrl} (HTTP $code) — real captures enabled.$extra',
            latencyMs: sw.elapsedMilliseconds);
      }
      return DeviceProbe(false,
          'Direct SDK: $directWhy\nWebAPI: port 8443 answered but not like SGIBIOSRV (HTTP $code) — response starts: "${body.replaceAll("\n", " ").substring(0, body.length > 180 ? 180 : body.length)}"',
          latencyMs: sw.elapsedMilliseconds);
    } catch (e) {
      sw.stop();
      return DeviceProbe(false,
          'Direct SDK: $directWhy\nWebAPI: nothing listening at ${AppConfig.sgibiosrvUrl}.\n'
          'Fix the Direct SDK line above (usually: plug in the scanner / reinstall the FDx SDK) — the desktop app needs NO WebAPI service.');
    }
  }

  /// Enrollment capture for worker registration.
  /// Windows + SGIBIOSRV → REAL template from the connected scanner.
  /// Anywhere else (or service missing) → simulated template, clearly marked.
  static String? lastEnrollError;
  static Future<EnrollCapture?> enrollCapture() async {
    lastEnrollError = null;
    if (Platform.isAndroid) {
      try {
        final st = Map<String, dynamic>.from(
            await _android.invokeMethod('status') as Map);
        if (st['deviceAttached'] == true && st['sdkPresent'] == true) {
          if (st['permission'] != true) {
            final granted =
                await _android.invokeMethod('requestPermission') as bool? ?? false;
            if (!granted) {
              lastEnrollError = 'USB permission denied — accept the prompt to use the scanner.';
              return null;
            }
          }
          final r = Map<String, dynamic>.from(await _android
              .invokeMethod('capture', {'timeoutMs': 10000}) as Map);
          if (r['template'] != null) {
            return EnrollCapture(r['template'] as String,
                (r['quality'] as num?)?.toInt() ?? 0,
                simulated: false);
          }
          lastEnrollError = (r['error'] as String?) ?? 'Capture failed.';
          return null; // real hardware present — let the user retry
        }
        // No SecuGen? Try a Mantra MFS100-class scanner (same ISO templates).
        final mantra = MantraAndroidDriver();
        if (await mantra.available()) {
          final r = await mantra.enrollCapture();
          if (r != null) return r;
          return null; // real device present — surface error, let user retry
        }
        // No scanner / no SDK → clearly-marked simulation below.
      } catch (_) {/* channel unavailable → simulate */}
    }
    if (Platform.isWindows) {
      // 1) Direct SDK — no service required.
      if (SgfpDirect.instance.ensureReady() == null) {
        final r = SgfpDirect.instance.captureTemplate();
        if (r.template != null) {
          return EnrollCapture(r.template!, r.quality, simulated: false);
        }
        lastEnrollError = r.error;
        return null; // real device present — surface the error, let user retry
      }
      // 2) WebAPI fallback.
      final sg = SgibiosrvDriver();
      if (await sg.available()) {
        final r = await sg.captureForEnroll();
        if (r != null) return r;
        lastEnrollError = sg.lastError;
        return null;
      }
    }
    await Future.delayed(const Duration(milliseconds: 900));
    final fake =
        'U0lNRk1EOg==${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}';
    return EnrollCapture(fake, 80 + Random().nextInt(15), simulated: true);
  }
}

class SimDriver implements BiometricDriver {
  final _rng = Random();

  @override
  Future<bool> available() async => true;

  @override
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates) async {
    await Future.delayed(const Duration(milliseconds: 900)); // capture latency
    if (candidates.isEmpty) return null;
    final worker = candidates[_rng.nextInt(candidates.length)];
    final score = 150 + _rng.nextInt(50); // 150–199 / 200
    return IdentifyResult(worker, score, simulated: true);
  }
}

class SgibiosrvDriver implements BiometricDriver {
  // SGIBIOSRV serves a SELF-SIGNED cert on https://localhost:8443 — trust it
  // for that exact host+port only (SecuGen's own utility does the same).
  // Without this, every call fails with a TLS handshake error even when the
  // service is running perfectly.
  static Dio _makeDio() {
    final dio = Dio(BaseOptions(
      connectTimeout: const Duration(seconds: 3),
      receiveTimeout: const Duration(seconds: 15),
    ));
    dio.httpClientAdapter = IOHttpClientAdapter(createHttpClient: () {
      final c = HttpClient();
      c.badCertificateCallback =
          (cert, host, port) => (host == 'localhost' || host == '127.0.0.1') && port == 8443;
      return c;
    });
    return dio;
  }

  final _dio = _makeDio();

  @override
  Future<bool> available() async {
    try {
      final r = await rawCapturePing();
      return r.$1 == 200 || r.$2.contains('ErrorCode');
    } catch (_) {
      return false;
    }
  }

  /// Diagnostics: one quick capture ping, returning (httpStatus, rawBody).
  Future<(int, String)> rawCapturePing() async {
    final r = await _dio.post('${AppConfig.sgibiosrvUrl}/SGIFPCapture',
        data: 'Timeout=100&Quality=50&licstr=&templateFormat=ISO',
        options: Options(
            contentType: 'text/plain;charset=UTF-8',
            responseType: ResponseType.plain,
            validateStatus: (_) => true));
    return (r.statusCode ?? 0, (r.data ?? '').toString());
  }

  /// Captures a REAL template. v0.9 has no on-device matcher yet, so this
  /// returns null (callers then use server-side identify when online, or the
  /// operator picks the worker manually with the capture recorded as proof).
  Future<String?> captureTemplate() async {
    final r = await _dio.post('${AppConfig.sgibiosrvUrl}/SGIFPCapture',
        data: 'Timeout=10000&Quality=50&licstr=&templateFormat=ISO',
        options: Options(contentType: 'text/plain;charset=UTF-8'));
    final data = r.data is Map ? r.data as Map : {};
    if (data['ErrorCode'] == 0) return data['TemplateBase64'] as String?;
    return null;
  }

  /// Real enrollment capture with quality (registration flow).
  /// Sets [lastError] with the device's own message when it fails.
  String? lastError;
  Future<EnrollCapture?> captureForEnroll() async {
    lastError = null;
    final r = await _dio.post('${AppConfig.sgibiosrvUrl}/SGIFPCapture',
        data: 'Timeout=10000&Quality=60&licstr=&templateFormat=ISO',
        options: Options(validateStatus: (_) => true, contentType: 'text/plain;charset=UTF-8'));
    final data = r.data is Map ? r.data as Map : {};
    if (data['ErrorCode'] == 0 && data['TemplateBase64'] != null) {
      return EnrollCapture(data['TemplateBase64'] as String,
          (data['ImageQuality'] as num?)?.toInt() ?? 0,
          simulated: false);
    }
    final ec = (data['ErrorCode'] as num?)?.toInt();
    lastError = ec != null
        ? (kSgiErrors[ec] ?? 'Device ErrorCode $ec')
        : 'Unexpected reply (HTTP ${r.statusCode}) — see Diagnostics.';
    return null;
  }

  @override
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates) async {
    final live = await captureTemplate();
    if (live == null) return null;
    Map<String, Object?>? best;
    var bestScore = -1;
    var secondScore = -1;
    for (final w in candidates) {
      var score = -1; // worker's best across enrolled fingers
      for (final stored in BiometricDriver.templatesOf(w)) {
        try {
          final r = await _dio.post('${AppConfig.sgibiosrvUrl}/SGIMatchScore',
              data: 'template1=${Uri.encodeComponent(live)}'
                  '&template2=${Uri.encodeComponent(stored)}'
                  '&licstr=&templateFormat=ISO',
              options: Options(contentType: 'text/plain;charset=UTF-8'));
          final data = r.data is Map ? r.data as Map : {};
          final s = (data['MatchingScore'] as num?)?.toInt() ?? -1;
          if (s > score) score = s;
        } catch (_) {/* keep trying others */}
      }
      if (score < 0) continue;
      if (score > bestScore) {
        secondScore = bestScore;
        bestScore = score;
        best = w;
      } else if (score > secondScore) {
        secondScore = score;
      }
    }
    if (best == null || bestScore < BiometricDriver.matchThreshold) return null;
    if (secondScore >= 0 &&
        bestScore - secondScore < BiometricDriver.matchMargin) {
      return null; // ambiguous between two workers — rescan
    }
    return IdentifyResult(best, bestScore);
  }
}

/// Windows DIRECT-SDK driver: capture + true on-device 1:N matching via
/// SGFPM_GetMatchingScore — fully offline, no service.
class SgfpDirectDriver implements BiometricDriver {
  @override
  Future<bool> available() async =>
      Platform.isWindows && SgfpDirect.instance.ensureReady() == null;

  @override
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates) async {
    final cap = SgfpDirect.instance.captureTemplate();
    final live = cap.template;
    if (live == null) return null;
    Map<String, Object?>? best;
    var bestScore = -1;
    var secondScore = -1;
    for (final w in candidates) {
      // Skip missing/simulated/garbage templates — only real enrollments match.
      final stored2 = BiometricDriver.templatesOf(w);
      if (stored2.isEmpty) continue;
      var score = -1;
      for (final stored in stored2) {
        final s = SgfpDirect.instance.matchScore(live, stored);
        if (s > score) score = s;
      }
      if (score > bestScore) {
        secondScore = bestScore;
        bestScore = score;
        best = w;
      } else if (score > secondScore) {
        secondScore = score;
      }
    }
    if (best == null || bestScore < BiometricDriver.matchThreshold) return null;
    if (secondScore >= 0 &&
        bestScore - secondScore < BiometricDriver.matchMargin) {
      return null; // ambiguous between two workers — rescan
    }
    return IdentifyResult(best, bestScore);
  }
}

/// Android USB-OTG driver for Mantra MFS100-class scanners (India's most
/// common brand) — reflection-bound like the SecuGen driver, so the app
/// ships without the SDK and activates when mantra.mfs100.jar is bundled.
///
/// Score scale: Mantra MatchISO returns 0–100000 (recommended accept 14000).
/// We accept/margin on the RAW scale, then normalise to 0–200 so scores are
/// stored and displayed consistently with SecuGen marks.
///
/// NOTE: the MFS110 is an Aadhaar L1 SECURE device — it encrypts fingerprints
/// inside the sensor and can never hand templates to any app (UIDAI design).
/// This driver therefore targets MFS100/MFS500-class L0 scanners only; the
/// diagnostics screen explains this when an L1 device is detected.
class MantraAndroidDriver implements BiometricDriver {
  static const _ch = MethodChannel('truecrew/sgfp');
  static const int rawThreshold = 14000; // Mantra's documented accept score
  static const int rawMargin = 7000;     // 1:N ambiguity margin on raw scale

  static int normalise(int raw) => (raw * 200 / 100000).round().clamp(0, 200);

  @override
  Future<bool> available() async {
    if (!Platform.isAndroid) return false;
    try {
      final st = Map<String, dynamic>.from(
          await _ch.invokeMethod('mantraStatus') as Map);
      if (st['deviceAttached'] != true || st['sdkPresent'] != true) return false;
      if (st['permission'] != true) {
        return await _ch.invokeMethod('requestPermission') as bool? ?? false;
      }
      return true;
    } catch (_) {
      return false;
    }
  }

  /// Enrollment capture — ISO 19794-2 template, same storage as SecuGen.
  Future<EnrollCapture?> enrollCapture() async {
    try {
      final r = Map<String, dynamic>.from(await _ch
          .invokeMethod('mantraCapture', {'timeoutMs': 12000}) as Map);
      final tpl = r['template'] as String?;
      if (tpl == null) {
        BiometricDriver.lastEnrollError = '${r['error'] ?? 'capture failed'}';
        return null;
      }
      return EnrollCapture(tpl, (r['quality'] as num?)?.toInt() ?? 0,
          simulated: false);
    } catch (e) {
      BiometricDriver.lastEnrollError = 'Mantra channel error: $e';
      return null;
    }
  }

  @override
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates) async {
    try {
      final r = Map<String, dynamic>.from(await _ch
          .invokeMethod('mantraCapture', {'timeoutMs': 10000}) as Map);
      final live = r['template'] as String?;
      if (live == null) return null;
      Map<String, Object?>? best;
      var bestRaw = -1;
      var secondRaw = -1;
      for (final w in candidates) {
        var raw = -1; // worker's best across enrolled fingers
        for (final stored in BiometricDriver.templatesOf(w)) {
          try {
            final m = Map<String, dynamic>.from(await _ch
                .invokeMethod('mantraMatch', {'t1': live, 't2': stored}) as Map);
            final r2 = (m['raw'] as num?)?.toInt() ?? -1;
            if (r2 > raw) raw = r2;
          } catch (_) {/* try next */}
        }
        if (raw < 0) continue;
        if (raw > bestRaw) {
          secondRaw = bestRaw;
          bestRaw = raw;
          best = w;
        } else if (raw > secondRaw) {
          secondRaw = raw;
        }
      }
      if (best == null || bestRaw < rawThreshold) return null;
      if (secondRaw >= 0 && bestRaw - secondRaw < rawMargin) {
        return null; // ambiguous between two workers — rescan
      }
      return IdentifyResult(best, normalise(bestRaw));
    } catch (_) {
      return null;
    }
  }
}

/// Android USB-OTG driver: capture + match through the native SecuGen SDK
/// channel — fully offline.
class AndroidSgDriver implements BiometricDriver {
  static const _ch = MethodChannel('truecrew/sgfp');

  @override
  Future<bool> available() async {
    if (!Platform.isAndroid) return false;
    try {
      final st = Map<String, dynamic>.from(await _ch.invokeMethod('status') as Map);
      if (st['deviceAttached'] != true || st['sdkPresent'] != true) return false;
      if (st['permission'] != true) {
        return await _ch.invokeMethod('requestPermission') as bool? ?? false;
      }
      return true;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates) async {
    try {
      final r = Map<String, dynamic>.from(
          await _ch.invokeMethod('capture', {'timeoutMs': 10000}) as Map);
      final live = r['template'] as String?;
      if (live == null) return null;
      Map<String, Object?>? best;
      var bestScore = -1;
      var secondScore = -1;
      for (final w in candidates) {
        var score = -1; // worker's best across enrolled fingers
        for (final stored in BiometricDriver.templatesOf(w)) {
          try {
            final m = Map<String, dynamic>.from(await _ch.invokeMethod(
                'matchScore', {'t1': live, 't2': stored}) as Map);
            final s2 = (m['score'] as num?)?.toInt() ?? -1;
            if (s2 > score) score = s2;
          } catch (_) {/* try next */}
        }
        if (score < 0) continue;
        if (score > bestScore) {
          secondScore = bestScore;
          bestScore = score;
          best = w;
        } else if (score > secondScore) {
          secondScore = score;
        }
      }
      if (best == null || bestScore < BiometricDriver.matchThreshold) return null;
      if (secondScore >= 0 &&
          bestScore - secondScore < BiometricDriver.matchMargin) {
        return null; // ambiguous between two workers — rescan
      }
      return IdentifyResult(best, bestScore);
    } catch (_) {
      return null;
    }
  }
}
