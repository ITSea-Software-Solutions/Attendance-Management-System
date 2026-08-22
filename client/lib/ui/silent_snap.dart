import 'dart:io';

import 'package:camera/camera.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

/// Best-effort, no-UI camera capture for gate PROOF photos: when a worker's
/// fingerprint verifies, quietly photograph the person at the gate and attach
/// it to the attendance record. Any failure (no camera, no permission, device
/// busy, a hung driver) returns null — the mark itself is never blocked.
///
/// Works on Android AND Windows (internal or external/USB webcams). Every
/// native call is timeout-guarded so a wedged camera driver cannot stall the
/// gate; if one camera fails the next one is tried.
Future<String?> silentSnap() async {
  List<CameraDescription> cameras;
  try {
    cameras =
        await availableCameras().timeout(const Duration(seconds: 4));
  } catch (_) {
    return null;
  }
  if (cameras.isEmpty) return null;

  // Prefer the FRONT camera (gate devices usually face the worker); on
  // Windows external webcams report no direction and land at the front of
  // the ordered list anyway.
  final ordered = [...cameras]..sort((a, b) {
    int rank(CameraDescription c) =>
        c.lensDirection == CameraLensDirection.front ? 0 : 1;
    return rank(a) - rank(b);
  });

  for (final desc in ordered) {
    CameraController? cam;
    try {
      cam = CameraController(desc, ResolutionPreset.medium,
          enableAudio: false);
      await cam.initialize().timeout(const Duration(seconds: 6));
      // Brief settle: some webcams deliver black frames immediately after
      // init (same trick the visible capture screen uses).
      await Future.delayed(const Duration(milliseconds: 350));
      final shot =
          await cam.takePicture().timeout(const Duration(seconds: 6));
      final dir = await getApplicationSupportDirectory();
      final dest = p.join(dir.path, 'proofs', '${const Uuid().v4()}.jpg');
      await Directory(p.dirname(dest)).create(recursive: true);
      await File(shot.path).copy(dest);
      return dest;
    } catch (_) {
      // try the next camera
    } finally {
      try {
        await cam?.dispose();
      } catch (_) {}
    }
  }
  return null;
}
