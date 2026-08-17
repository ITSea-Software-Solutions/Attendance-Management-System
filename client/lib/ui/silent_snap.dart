import 'dart:io';

import 'package:camera/camera.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

/// Best-effort, no-UI camera capture for gate PROOF photos: when a worker's
/// fingerprint verifies, quietly photograph the person at the gate and attach
/// it to the attendance record. Any failure (no camera, no permission, device
/// busy) returns null — the mark itself must never be blocked by this.
Future<String?> silentSnap() async {
  CameraController? cam;
  try {
    final cameras = await availableCameras();
    if (cameras.isEmpty) return null;
    // Prefer the FRONT camera: at a gate the device usually faces the worker.
    var idx = cameras.indexWhere(
        (c) => c.lensDirection == CameraLensDirection.front);
    if (idx < 0) idx = 0;
    cam = CameraController(cameras[idx], ResolutionPreset.medium,
        enableAudio: false);
    await cam.initialize();
    final shot = await cam.takePicture().timeout(const Duration(seconds: 6));
    final dir = await getApplicationSupportDirectory();
    final dest = p.join(dir.path, 'proofs', '${const Uuid().v4()}.jpg');
    await Directory(p.dirname(dest)).create(recursive: true);
    await File(shot.path).copy(dest);
    return dest;
  } catch (_) {
    return null;
  } finally {
    try { await cam?.dispose(); } catch (_) {}
  }
}
