import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import '../core/scope.dart';
import 'gate_result.dart';

/// Face attendance INSIDE the app — on-device precision, hardware-free:
///  1. Live camera preview (back camera by default — point at the worker).
///  2. Capture is GATED on-device by ML Kit: a real face must be present,
///     reasonably large, and with both eyes open (basic liveness/quality).
///  3. The photo goes to the server for ArcFace 1:N identification and is
///     RE-VERIFIED server-side at mark time (photo doubles as proof).
/// Online-only (identification runs server-side); fingerprint covers offline.
class FaceAttendanceScreen extends StatefulWidget {
  const FaceAttendanceScreen({super.key});
  @override
  State<FaceAttendanceScreen> createState() => _FaceAttendanceScreenState();
}

class _FaceAttendanceScreenState extends State<FaceAttendanceScreen> {
  CameraController? _cam;
  List<CameraDescription> _cameras = const [];
  int _camIndex = 0;
  bool _busy = false;
  String? _status;

  @override
  void initState() {
    super.initState();
    _initCamera();
  }

  Future<void> _initCamera() async {
    try {
      _cameras = await availableCameras();
      if (_cameras.isEmpty) {
        setState(() => _status = 'No camera on this device.');
        return;
      }
      // Prefer the back camera for pointing at workers.
      _camIndex = _cameras.indexWhere(
          (c) => c.lensDirection == CameraLensDirection.back);
      if (_camIndex < 0) _camIndex = 0;
      await _openCamera();
    } catch (e) {
      setState(() => _status = 'Camera unavailable: $e');
    }
  }

  Future<void> _openCamera() async {
    await _cam?.dispose();
    final c = CameraController(_cameras[_camIndex], ResolutionPreset.medium,
        enableAudio: false);
    await c.initialize();
    if (mounted) setState(() => _cam = c);
  }

  Future<void> _flipCamera() async {
    if (_cameras.length < 2) return;
    _camIndex = (_camIndex + 1) % _cameras.length;
    await _openCamera();
  }

  @override
  void dispose() {
    _cam?.dispose();
    super.dispose();
  }

  Future<void> _scan() async {
    final app = AppScope.of(context);
    if (!app.online) {
      setState(() => _status =
          'Face attendance needs internet (identification runs on the server). Use Fingerprint for offline marking.');
      return;
    }
    final cam = _cam;
    if (cam == null || !cam.value.isInitialized) {
      setState(() => _status = 'Camera not ready.');
      return;
    }
    setState(() { _busy = true; _status = 'Capturing…'; });
    try {
      final shot = await cam.takePicture();
      final dir = await getApplicationSupportDirectory();
      final path = p.join(dir.path, 'faceprobe', '${const Uuid().v4()}.jpg');
      await Directory(p.dirname(path)).create(recursive: true);
      await File(shot.path).copy(path);

      // ── On-device gate (Android only — ML Kit is mobile-only; on Windows
      // the server's ArcFace identify+re-verify is the sole gate) ──
      if (Platform.isAndroid) {
        setState(() => _status = 'Checking face quality on-device…');
        final detector = FaceDetector(
            options: FaceDetectorOptions(
                performanceMode: FaceDetectorMode.accurate,
                enableClassification: true));
        final faces =
            await detector.processImage(InputImage.fromFilePath(path));
        await detector.close();
        if (faces.isEmpty) {
          setState(() { _busy = false; _status = 'No face in frame — align the worker\'s face and try again.'; });
          return;
        }
        final f = faces.reduce((a, b) =>
            a.boundingBox.width * a.boundingBox.height >
                    b.boundingBox.width * b.boundingBox.height
                ? a
                : b);
        if (f.boundingBox.width < 120) {
          setState(() { _busy = false; _status = 'Face too small/far — move closer and retry.'; });
          return;
        }
        final eyes = ((f.leftEyeOpenProbability ?? 1) +
                (f.rightEyeOpenProbability ?? 1)) / 2;
        if (eyes < 0.3) {
          setState(() { _busy = false; _status = 'Eyes appear closed — ask the worker to look at the camera.'; });
          return;
        }
      }

      // ── Server 1:N identify ──
      setState(() => _status = 'Identifying worker…');
      final match = await app.identifyFace(path);
      if (!mounted) return;
      setState(() => _busy = false);
      await _confirm(match, path);
    } catch (e) {
      final t = e.toString();
      String msg = 'Identification failed — try again.';
      if (t.contains('404')) msg = 'No face match found among deployed workers.';
      if (t.contains('422')) msg = 'Photo rejected by the server — retake facing the camera.';
      setState(() { _busy = false; _status = msg; });
    }
  }

  Future<void> _confirm(Map<String, dynamic> w, String photoPath) async {
    final app = AppScope.of(context);
    final type = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('${w['name']}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('${w['vendor'] ?? ''}'),
          const SizedBox(height: 6),
          Text('Face similarity: ${((w['face_score'] as num? ?? 0) * 100).round()}%'),
          const SizedBox(height: 12),
          Text('Mark ${w['pending_type']}?',
              style: Theme.of(context).textTheme.titleMedium),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          TextButton(
              onPressed: () => Navigator.pop(
                  context, w['pending_type'] == 'IN' ? 'OUT' : 'IN'),
              child: Text(w['pending_type'] == 'IN' ? 'OUT instead' : 'IN instead')),
          FilledButton(
              onPressed: () => Navigator.pop(context, w['pending_type'] as String),
              child: Text('Mark ${w['pending_type']}')),
        ],
      ),
    );
    if (type == null) return;
    setState(() { _busy = true; _status = 'Marking $type…'; });
    try {
      await app.markFace(w, type, photoPath);
      if (!mounted) return;
      setState(() { _busy = false; _status = null; });
      await showGateResult(
        context,
        name: '${w['name']}',
        type: type,
        workerServerId: (w['worker_id'] as num?)?.toInt(),
        method: 'face',
        proofPath: photoPath,
      );
    } catch (e) {
      setState(() { _busy = false; _status = 'Mark failed — ${e.toString().contains("422") ? "face did not re-verify / sequence invalid" : "connection issue"}. Try again.'; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final cam = _cam;
    return Scaffold(
      body: Column(children: [
        Expanded(
          child: cam != null && cam.value.isInitialized
              ? Stack(fit: StackFit.expand, children: [
                  CameraPreview(cam),
                  if (_cameras.length > 1)
                    Positioned(
                      top: 12, right: 12,
                      child: FloatingActionButton.small(
                        heroTag: 'flip',
                        onPressed: _flipCamera,
                        child: const Icon(Icons.cameraswitch),
                      ),
                    ),
                ])
              : Center(child: Text(_status ?? 'Starting camera…')),
        ),
        Padding(
          padding: const EdgeInsets.all(14),
          child: Column(children: [
            if (_status != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(_status!, textAlign: TextAlign.center),
              ),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _busy ? null : _scan,
                icon: _busy
                    ? const SizedBox(width: 16, height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.face_retouching_natural),
                label: Text(_busy ? 'Working…' : 'Scan face'),
              ),
            ),
            const SizedBox(height: 4),
            Text('On-device checks: face present · size · eyes open. Identification + re-verification on the server.',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center),
          ]),
        ),
      ]),
    );
  }
}
