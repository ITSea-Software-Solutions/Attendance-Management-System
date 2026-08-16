import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import '../biometric/driver.dart';
import '../core/scope.dart';

/// Full worker registration — details + consent + Aadhaar + FINGERPRINT +
/// PHOTO, entirely from the app (offline-capable; queues and syncs).
///
/// Fingerprint: on Windows with the SecuGen SGIBIOSRV service running this is
/// a REAL capture from the connected scanner; elsewhere it's a clearly-marked
/// simulated capture until the Android USB-OTG SDK driver lands. (A phone's
/// built-in sensor can never capture worker fingerprints — OS restriction.)
///
/// Photo: taken with the device camera; on sync the server stores it privately
/// and AUTO-ENROLLS THE FACE, so the worker can also mark attendance by camera.
class RegisterWorkerScreen extends StatefulWidget {
  const RegisterWorkerScreen({super.key});
  @override
  State<RegisterWorkerScreen> createState() => _RegisterWorkerScreenState();
}

class _RegisterWorkerScreenState extends State<RegisterWorkerScreen> {
  final _name = TextEditingController();
  final _aadhaar = TextEditingController();
  final _phone = TextEditingController();
  String? _gender;
  bool _consent = false;

  EnrollCapture? _fp;
  bool _fpBusy = false;
  String? _photoPath;
  bool _busy = false;
  String? _error;

  Future<void> _captureFingerprint() async {
    setState(() => _fpBusy = true);
    final r = await BiometricDriver.enrollCapture();
    setState(() {
      _fp = r;
      _fpBusy = false;
      if (r == null) _error = 'Capture failed — check the scanner and try again.';
    });
  }

  Future<void> _takePhoto(ImageSource source) async {
    try {
      final x = await ImagePicker().pickImage(
          source: source, maxWidth: 1024, imageQuality: 85);
      if (x == null) return;
      // Persist outside the picker's temp dir so it survives until synced.
      final dir = await getApplicationSupportDirectory();
      final dest = p.join(dir.path, 'photos', '${const Uuid().v4()}.jpg');
      await Directory(p.dirname(dest)).create(recursive: true);
      await File(x.path).copy(dest);
      setState(() => _photoPath = dest);
    } catch (e) {
      setState(() => _error =
          'Camera unavailable — try "Pick from gallery/files" instead.');
    }
  }

  Future<void> _save() async {
    setState(() => _error = null);
    if (_name.text.trim().isEmpty) {
      setState(() => _error = 'Name is required.');
      return;
    }
    if (!RegExp(r'^\d{12}$').hasMatch(_aadhaar.text.trim())) {
      setState(() => _error = 'Aadhaar must be exactly 12 digits.');
      return;
    }
    if (!_consent) {
      setState(() => _error = "Please confirm the worker's consent.");
      return;
    }
    if (_fp == null) {
      setState(() => _error = 'Capture the fingerprint before saving.');
      return;
    }
    setState(() => _busy = true);
    final app = AppScope.of(context);
    final err = await app.registerWorker(
      name: _name.text.trim(),
      aadhaar: _aadhaar.text.trim(),
      phone: _phone.text.trim().isEmpty ? null : _phone.text.trim(),
      gender: _gender,
      fingerprintTemplate: _fp!.template,
      fingerprintQuality: _fp!.quality,
      fingerprintSimulated: _fp!.simulated,
      photoPath: _photoPath,
    );
    setState(() => _busy = false);
    if (err != null) {
      setState(() => _error = err);
      return;
    }
    if (mounted) {
      Navigator.pop(context, true);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          duration: const Duration(seconds: 6),
          content: Text(app.online
              ? 'Worker registered — syncing. ${_photoPath != null ? "Photo will enable camera (face) attendance too." : ""}'
              : 'Worker saved offline — will sync (and enroll face from photo) when online.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Register worker')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              TextField(
                  controller: _name,
                  decoration:
                      const InputDecoration(labelText: 'Full name *')),
              const SizedBox(height: 10),
              TextField(
                controller: _aadhaar,
                keyboardType: TextInputType.number,
                maxLength: 12,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(
                    labelText: 'Aadhaar number * (12 digits)',
                    helperText: 'Mandatory. Only the last 4 digits are stored.'),
              ),
              TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'Phone')),
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                initialValue: _gender,
                decoration: const InputDecoration(labelText: 'Gender'),
                items: const [
                  DropdownMenuItem(value: 'M', child: Text('Male')),
                  DropdownMenuItem(value: 'F', child: Text('Female')),
                  DropdownMenuItem(value: 'O', child: Text('Other')),
                ],
                onChanged: (v) => _gender = v,
              ),
              const SizedBox(height: 14),

              // ── Fingerprint ─────────────────────────────────────────────
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        const Icon(Icons.fingerprint, color: Color(0xFF10685A)),
                        const SizedBox(width: 8),
                        Text('Fingerprint *',
                            style: Theme.of(context).textTheme.titleSmall),
                        const Spacer(),
                        if (_fp != null)
                          Chip(
                              visualDensity: VisualDensity.compact,
                              avatar: const Icon(Icons.check,
                                  size: 14, color: Colors.teal),
                              label: Text(
                                  'Q ${_fp!.quality}${_fp!.simulated ? " · simulated" : ""}')),
                      ]),
                      const SizedBox(height: 6),
                      Text(
                        Platform.isWindows
                            ? 'Uses the connected SecuGen scanner (SGIBIOSRV). If the service is not running, a simulated capture is used.'
                            : 'Real USB-OTG scanner support arrives with the SecuGen Android SDK — until then this is a simulated capture. (Phone built-in sensors cannot capture worker fingerprints — OS rule.)',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      FilledButton.tonalIcon(
                        onPressed: _fpBusy ? null : _captureFingerprint,
                        icon: _fpBusy
                            ? const SizedBox(
                                width: 14,
                                height: 14,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2))
                            : const Icon(Icons.fingerprint),
                        label: Text(_fp == null
                            ? (_fpBusy ? 'Scanning…' : 'Scan fingerprint')
                            : 'Re-scan'),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 10),

              // ── Photo (enables camera/face attendance) ──────────────────
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        const Icon(Icons.photo_camera,
                            color: Color(0xFF10685A)),
                        const SizedBox(width: 8),
                        Text('Photo (recommended)',
                            style: Theme.of(context).textTheme.titleSmall),
                        const Spacer(),
                        if (_photoPath != null)
                          ClipRRect(
                            borderRadius: BorderRadius.circular(6),
                            child: Image.file(File(_photoPath!),
                                width: 42, height: 42, fit: BoxFit.cover),
                          ),
                      ]),
                      const SizedBox(height: 6),
                      Text(
                        'On sync, the photo also enrolls the worker for CAMERA (face) attendance — no scanner needed at the gate.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      Row(children: [
                        FilledButton.tonalIcon(
                            onPressed: () => _takePhoto(ImageSource.camera),
                            icon: const Icon(Icons.photo_camera),
                            label: const Text('Camera')),
                        const SizedBox(width: 8),
                        OutlinedButton.icon(
                            onPressed: () => _takePhoto(ImageSource.gallery),
                            icon: const Icon(Icons.folder_open),
                            label: const Text('Gallery / files')),
                      ]),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 10),

              CheckboxListTile(
                value: _consent,
                onChanged: (v) => setState(() => _consent = v ?? false),
                controlAffinity: ListTileControlAffinity.leading,
                contentPadding: EdgeInsets.zero,
                title: const Text(
                  'Worker has given informed consent for identity & biometric processing (required)',
                  style: TextStyle(fontSize: 13),
                ),
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(_error!,
                      style:
                          const TextStyle(color: Colors.red, fontSize: 13)),
                ),
              FilledButton(
                onPressed: _busy ? null : _save,
                child: Text(_busy ? 'Saving…' : 'Save worker'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
