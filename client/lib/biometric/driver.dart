import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:dio/io.dart';

import '../core/config.dart';

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

  static Future<BiometricDriver> best() async {
    if (Platform.isWindows) {
      final sg = SgibiosrvDriver();
      if (await sg.available()) return sg;
    }
    return SimDriver();
  }

  /// Diagnostics: probe the fingerprint scanner service with timing + detail.
  static Future<DeviceProbe> probeScanner() async {
    if (!Platform.isWindows) {
      return DeviceProbe(false,
          'No USB scanner driver on this platform yet — fingerprint runs in SIMULATION. Real Android USB-OTG support arrives with the SecuGen FDx SDK.');
    }
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
            'SGIBIOSRV responding at ${AppConfig.sgibiosrvUrl} (HTTP $code) — real captures enabled.$extra',
            latencyMs: sw.elapsedMilliseconds);
      }
      return DeviceProbe(false,
          'Port 8443 answered but not like SGIBIOSRV.\nHTTP $code — response starts: "${body.replaceAll("\n", " ").substring(0, body.length > 180 ? 180 : body.length)}"\n'
          'Send this text to support — it identifies exactly what is listening there.',
          latencyMs: sw.elapsedMilliseconds);
    } catch (e) {
      sw.stop();
      return DeviceProbe(false,
          'Cannot reach the SecuGen WebAPI (SGIBIOSRV) at ${AppConfig.sgibiosrvUrl}.\n'
          'If the SecuGen diagnostic utility captures fine but this fails, the driver is OK — the WEBAPI SERVICE is missing or stopped: '
          'install "SecuGen WebAPI" from secugen.com/download, start SGIBIOSRV in services.msc, then Retry.\n(${e.runtimeType})');
    }
  }

  /// Enrollment capture for worker registration.
  /// Windows + SGIBIOSRV → REAL template from the connected scanner.
  /// Anywhere else (or service missing) → simulated template, clearly marked.
  static Future<EnrollCapture?> enrollCapture() async {
    if (Platform.isWindows) {
      final sg = SgibiosrvDriver();
      if (await sg.available()) {
        final r = await sg.captureForEnroll();
        if (r != null) return r;
        return null; // real scanner present but capture failed — let user retry
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
    // Real 1:N on-device matching arrives with the SDK matcher integration.
    // Until then the UI routes real captures through server identify (online)
    // or manual selection; SimDriver covers offline demo behaviour.
    return null;
  }
}
