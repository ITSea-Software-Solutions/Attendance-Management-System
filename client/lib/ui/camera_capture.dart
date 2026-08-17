import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

/// In-app camera capture screen — needed on DESKTOP, where image_picker has
/// no native camera UI (its Windows/macOS/Linux implementations only open a
/// file dialog; ImageSource.camera throws without a cameraDelegate).
/// Reuses the same `camera`/`camera_windows` backend as the Face tab.
///
/// Returns the captured photo's file path, or null when cancelled.
Future<String?> captureWithCamera(BuildContext context) {
  return Navigator.push<String>(
    context,
    MaterialPageRoute(builder: (_) => const _CameraCaptureScreen()),
  );
}

class _CameraCaptureScreen extends StatefulWidget {
  const _CameraCaptureScreen();
  @override
  State<_CameraCaptureScreen> createState() => _CameraCaptureScreenState();
}

class _CameraCaptureScreenState extends State<_CameraCaptureScreen> {
  CameraController? _cam;
  List<CameraDescription> _cameras = const [];
  int _camIndex = 0;
  bool _shooting = false;
  String? _status;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    try {
      _cameras = await availableCameras();
      if (_cameras.isEmpty) {
        setState(() => _status = 'No camera found on this device.');
        return;
      }
      await _open();
    } catch (e) {
      setState(() => _status = 'Camera unavailable: $e');
    }
  }

  Future<void> _open() async {
    await _cam?.dispose();
    final c = CameraController(_cameras[_camIndex], ResolutionPreset.medium,
        enableAudio: false);
    try {
      await c.initialize();
      if (mounted) setState(() => _cam = c);
    } catch (e) {
      setState(() => _status = 'Could not start the camera: $e');
    }
  }

  Future<void> _flip() async {
    if (_cameras.length < 2) return;
    _camIndex = (_camIndex + 1) % _cameras.length;
    await _open();
  }

  Future<void> _shoot() async {
    final cam = _cam;
    if (cam == null || !cam.value.isInitialized || _shooting) return;
    setState(() => _shooting = true);
    try {
      final shot =
          await cam.takePicture().timeout(const Duration(seconds: 12));
      // Windows (Media Foundation): the native photo sink can still be
      // finishing when Dart returns — tearing the camera down immediately
      // hard-crashes the app. Let it settle, copy the file out, THEN
      // dispose in a controlled order, and only then leave the screen.
      await Future.delayed(const Duration(milliseconds: 350));
      final dir = await getApplicationSupportDirectory();
      final dest = p.join(dir.path, 'captures', '${const Uuid().v4()}.jpg');
      await Directory(p.dirname(dest)).create(recursive: true);
      await File(shot.path).copy(dest);
      if (!mounted) return;
      setState(() => _cam = null); // detach the preview widget first
      try { await cam.dispose(); } catch (_) {}
      await Future.delayed(const Duration(milliseconds: 120));
      if (mounted) Navigator.pop(context, dest);
    } catch (e) {
      if (mounted) {
        setState(() { _shooting = false; _status = 'Capture failed: $e — try again or use Gallery/files.'; });
      }
    }
  }

  @override
  void dispose() {
    final c = _cam;
    _cam = null;
    if (c != null) {
      // ignore: discarded_futures
      c.dispose().catchError((_) {});
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cam = _cam;
    return Scaffold(
      appBar: AppBar(title: const Text('Take photo')),
      body: Column(children: [
        Expanded(
          child: cam != null && cam.value.isInitialized
              ? Stack(fit: StackFit.expand, children: [
                  CameraPreview(cam),
                  if (_cameras.length > 1)
                    Positioned(
                      top: 12, right: 12,
                      child: FloatingActionButton.small(
                        heroTag: 'flip-capture',
                        onPressed: _flip,
                        child: const Icon(Icons.cameraswitch),
                      ),
                    ),
                ])
              : Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Text(_status ?? 'Starting camera…',
                        textAlign: TextAlign.center),
                  )),
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Cancel'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                flex: 2,
                child: FilledButton.icon(
                  onPressed:
                      (cam?.value.isInitialized ?? false) && !_shooting
                          ? _shoot
                          : null,
                  icon: _shooting
                      ? const SizedBox(width: 16, height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.photo_camera),
                  label: Text(_shooting ? 'Capturing…' : 'Capture'),
                ),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}
