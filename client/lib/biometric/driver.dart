import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart';

import '../core/config.dart';

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
  final _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 3),
    receiveTimeout: const Duration(seconds: 15),
  ));

  @override
  Future<bool> available() async {
    try {
      // SGIBIOSRV answers on POST /SGIFPCapture; a quick reachability probe.
      final r = await _dio.post('${AppConfig.sgibiosrvUrl}/SGIFPCapture',
          data: 'Timeout=1&Quality=50&licstr=&templateFormat=ISO',
          options: Options(
              contentType: 'text/plain;charset=UTF-8',
              validateStatus: (_) => true));
      return r.statusCode == 200;
    } catch (_) {
      return false;
    }
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

  @override
  Future<IdentifyResult?> identify(List<Map<String, Object?>> candidates) async {
    // Real 1:N on-device matching arrives with the SDK matcher integration.
    // Until then the UI routes real captures through server identify (online)
    // or manual selection; SimDriver covers offline demo behaviour.
    return null;
  }
}
