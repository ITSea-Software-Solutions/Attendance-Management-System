import 'dart:convert';
import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import '../biometric/driver.dart';
import '../core/scope.dart';
import 'camera_capture.dart';

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
  final _dob = TextEditingController();
  String? _gender;
  bool _consent = false;

  // Aadhaar PDF import (same flow as the web portal, right in the app)
  String? _pdfPath;
  String? _pdfMasked; // masked PDFs carry only the last 4 — manual entry must match
  bool _pdfBusy = false;
  String? _pdfNote;

  // Aadhaar photo (extracted from the PDF) + live-photo identity check
  String? _aadhaarPhotoB64;
  bool _verifyBusy = false;
  double? _faceSim; // 0..1 similarity when both photos verified
  bool? _faceMatch;
  String? _verifyNote;

  EnrollCapture? _fp;
  bool _fpBusy = false;
  String? _photoPath;
  bool _photoBusy = false;
  String? _photoNote; // shown INSIDE the Photos card, where the user looks
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // Live checklist: re-render as the name/Aadhaar fields are typed.
    _name.addListener(_onFieldChange);
    _aadhaar.addListener(_onFieldChange);
    _recoverLostPhoto();
  }

  /// Android can kill this app while the camera is open; the captured photo
  /// then arrives via retrieveLostData() after the app restarts. Without
  /// this, the photo silently vanishes — "I took it but nothing happened".
  Future<void> _recoverLostPhoto() async {
    if (!Platform.isAndroid) return;
    try {
      final lost = await ImagePicker().retrieveLostData();
      final f = lost.file;
      if (!lost.isEmpty && f != null) {
        await _acceptPhoto(f.path);
      }
    } catch (_) {/* nothing to recover */}
  }

  void _onFieldChange() => setState(() {});

  @override
  void dispose() {
    _name.removeListener(_onFieldChange);
    _aadhaar.removeListener(_onFieldChange);
    for (final c in [_name, _aadhaar, _phone, _dob]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _importAadhaarPdf() async {
    final app = AppScope.of(context);
    if (!app.online) {
      setState(() => _pdfNote =
          'PDF extraction needs internet — fill the fields manually while offline.');
      return;
    }
    final picked = await FilePicker.platform.pickFiles(
        type: FileType.custom, allowedExtensions: ['pdf']);
    final src = picked?.files.single.path;
    if (src == null) return;
    if (!mounted) return;
    final password = await showDialog<String>(
      context: context,
      builder: (context) {
        final c = TextEditingController();
        return AlertDialog(
          title: const Text('PDF password'),
          content: TextField(
            controller: c,
            autofocus: true,
            decoration: const InputDecoration(
                labelText: 'Password (if the PDF asks for one)',
                helperText:
                    'UIDAI format: first 4 letters of the name in CAPITALS + birth year, e.g. NARE1955',
                helperMaxLines: 3),
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Cancel')),
            FilledButton(
                onPressed: () => Navigator.pop(context, c.text.trim()),
                child: const Text('Extract')),
          ],
        );
      },
    );
    if (password == null) return; // cancelled
    setState(() { _pdfBusy = true; _pdfNote = null; _error = null; });
    try {
      // Keep a private copy: uploaded to the worker's record after sync.
      final dir = await getApplicationSupportDirectory();
      final dest = p.join(dir.path, 'aadhaar', '${const Uuid().v4()}.pdf');
      await Directory(p.dirname(dest)).create(recursive: true);
      await File(src).copy(dest);

      final app2 = mounted ? AppScope.of(context) : app;
      final data = await app2.extractAadhaar(dest, password: password);

      final full = '${data['aadhaar_number'] ?? ''}';
      final masked = '${data['aadhaar_number_masked'] ?? ''}';
      setState(() {
        _pdfPath = dest;
        final pb = '${data['photo_base64'] ?? ''}';
        if (pb.length > 100) _aadhaarPhotoB64 = pb;
        if ('${data['name'] ?? ''}'.isNotEmpty) _name.text = '${data['name']}';
        final g = '${data['gender'] ?? ''}'.toUpperCase();
        if (g.startsWith('M')) _gender = 'M';
        if (g.startsWith('F')) _gender = 'F';
        var dob = '${data['dob'] ?? ''}';
        final dm = RegExp(r'^(\d{2})[/-](\d{2})[/-](\d{4})$').firstMatch(dob);
        if (dm != null) dob = '${dm.group(3)}-${dm.group(2)}-${dm.group(1)}';
        if (dob.isNotEmpty) _dob.text = dob;
        if (RegExp(r'^\d{12}$').hasMatch(full)) {
          _aadhaar.text = full;
          _pdfMasked = null;
          _pdfNote = 'Extracted — review the details, then continue.';
        } else if (masked.isNotEmpty) {
          _pdfMasked = masked;
          _pdfNote =
              'Masked PDF (shows only …${masked.length >= 4 ? masked.substring(masked.length - 4) : masked}). '
              'Type the full 12-digit Aadhaar — the last 4 must match the PDF.';
        }
      });
    } catch (e) {
      final t = e.toString();
      setState(() => _pdfNote = t.contains('422')
          ? 'Could not read that PDF — wrong password, or not an Aadhaar PDF.'
          : 'Extraction failed — check the connection and retry.');
    } finally {
      if (mounted) setState(() => _pdfBusy = false);
    }
    _maybeVerifyFaces();
  }

  /// Compare the Aadhaar PDF photo with the live photo (server-side ArcFace)
  /// the moment both exist. Advisory: warns on low score, never blocks —
  /// Aadhaar photos are often many years old.
  Future<void> _maybeVerifyFaces() async {
    final app = AppScope.of(context);
    if (_aadhaarPhotoB64 == null || _photoPath == null) return;
    if (!app.online) {
      setState(() => _verifyNote = 'Photo match check runs when online.');
      return;
    }
    setState(() { _verifyBusy = true; _verifyNote = null; _faceSim = null; _faceMatch = null; });
    try {
      final r = await app.verifyAadhaarFace(_aadhaarPhotoB64!, _photoPath!);
      setState(() {
        _verifyBusy = false;
        if (r['similarity'] == null) {
          _verifyNote = '${r['message'] ?? 'Could not compare the photos.'}';
        } else {
          _faceSim = (r['similarity'] as num).toDouble();
          _faceMatch = r['match'] == true;
        }
      });
    } catch (_) {
      setState(() { _verifyBusy = false; _verifyNote = 'Match check failed — you can still save.'; });
    }
  }

  Future<void> _captureFingerprint() async {
    setState(() => _fpBusy = true);
    final r = await BiometricDriver.enrollCapture();
    setState(() {
      _fp = r;
      _fpBusy = false;
      if (r == null) {
        _error = BiometricDriver.lastEnrollError ??
            'Capture failed — check the scanner and try again.';
      }
    });
  }

  Future<void> _takePhoto(ImageSource source) async {
    // Desktop (Windows/macOS/Linux): image_picker has no camera UI — use our
    // own capture screen on the same camera backend the Face tab uses.
    if (source == ImageSource.camera && !Platform.isAndroid && !Platform.isIOS) {
      final path = await captureWithCamera(context);
      if (path != null) await _acceptPhoto(path);
      return;
    }
    XFile? x;
    try {
      x = await ImagePicker().pickImage(
          source: source, maxWidth: 1024, imageQuality: 85);
    } catch (e) {
      setState(() =>
          _photoNote = 'Camera unavailable ($e) — try "Gallery / files" instead.');
      return;
    }
    if (x == null) {
      // User cancelled — or Android relaunched us mid-capture (the photo
      // then arrives via retrieveLostData in initState).
      return;
    }
    await _acceptPhoto(x.path);
  }

  /// Persist + validate a captured/picked photo, with VISIBLE progress and
  /// errors inside the Photos card (not buried near the Save button).
  Future<void> _acceptPhoto(String srcPath) async {
    setState(() { _photoBusy = true; _photoNote = null; });
    try {
      // Persist outside the picker's temp dir so it survives until synced.
      final dir = await getApplicationSupportDirectory();
      final dest = p.join(dir.path, 'photos', '${const Uuid().v4()}.jpg');
      await Directory(p.dirname(dest)).create(recursive: true);
      await File(srcPath).copy(dest);

      // On-device precision (Android): a real face must be in frame. If ML
      // Kit itself is unavailable on this device, ACCEPT the photo — the
      // server validates it again at face-enrollment.
      if (Platform.isAndroid) {
        try {
          final detector = FaceDetector(
              options: FaceDetectorOptions(
                  performanceMode: FaceDetectorMode.accurate));
          final faces =
              await detector.processImage(InputImage.fromFilePath(dest));
          await detector.close();
          if (faces.isEmpty) {
            await File(dest).delete();
            setState(() {
              _photoBusy = false;
              _photoNote =
                  'No face detected — retake with the worker facing the camera in good light.';
            });
            return;
          }
        } catch (_) {
          setState(() => _photoNote =
              'On-device face check unavailable — the server will validate on sync.');
        }
      }
      setState(() { _photoBusy = false; _photoPath = dest; });
      _maybeVerifyFaces();
    } catch (e) {
      setState(() {
        _photoBusy = false;
        _photoNote = 'Could not save the photo ($e) — retry.';
      });
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
    if (_pdfMasked != null && _pdfMasked!.length >= 4) {
      final want = _pdfMasked!.substring(_pdfMasked!.length - 4);
      if (!_aadhaar.text.trim().endsWith(want)) {
        setState(() =>
            _error = 'Last 4 digits must match the imported PDF (…$want).');
        return;
      }
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
      dob: _dob.text.trim().isEmpty ? null : _dob.text.trim(),
      gender: _gender,
      fingerprintTemplate: _fp!.template,
      fingerprintQuality: _fp!.quality,
      fingerprintSimulated: _fp!.simulated,
      photoPath: _photoPath,
      aadhaarPdfPath: _pdfPath,
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
              // ── Aadhaar PDF import (auto-fill, same as web) ─────────────
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        const Icon(Icons.picture_as_pdf,
                            color: Color(0xFF10685A)),
                        const SizedBox(width: 8),
                        Text('Import Aadhaar PDF',
                            style: Theme.of(context).textTheme.titleSmall),
                        const Spacer(),
                        if (_pdfPath != null)
                          const Icon(Icons.check_circle,
                              size: 18, color: Colors.teal),
                      ]),
                      const SizedBox(height: 6),
                      Text(
                        'Pick the UIDAI e-Aadhaar PDF — name, DOB, gender and Aadhaar auto-fill, and the PDF attaches to the worker on sync. Needs internet; manual entry always works.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      FilledButton.tonalIcon(
                        onPressed: _pdfBusy ? null : _importAadhaarPdf,
                        icon: _pdfBusy
                            ? const SizedBox(width: 14, height: 14,
                                child: CircularProgressIndicator(strokeWidth: 2))
                            : const Icon(Icons.upload_file),
                        label: Text(_pdfPath == null
                            ? (_pdfBusy ? 'Extracting…' : 'Pick PDF & extract')
                            : 'Re-import'),
                      ),
                      if (_pdfNote != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text(_pdfNote!,
                              style: TextStyle(
                                  fontSize: 12,
                                  color: _pdfPath != null
                                      ? Colors.teal
                                      : Colors.orange.shade800)),
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 10),
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
              TextField(
                  controller: _dob,
                  decoration: const InputDecoration(
                      labelText: 'Date of birth (YYYY-MM-DD)')),
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
                            ? 'Captures directly from the connected SecuGen scanner via its SDK — no extra service needed. (Simulation is the automatic fallback.)'
                            : 'Plug a SecuGen scanner in via USB-OTG and tap Allow — the SDK is bundled, captures are REAL. Without a scanner this is a clearly-marked simulated capture.',
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

              // ── Photos: Aadhaar (from PDF) + live — with identity match ──
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
                        Text('Photos & identity match',
                            style: Theme.of(context).textTheme.titleSmall),
                      ]),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          _photoSlot(
                            label: 'Aadhaar photo',
                            image: _aadhaarPhotoB64 != null
                                ? Image.memory(base64Decode(_aadhaarPhotoB64!),
                                    fit: BoxFit.cover)
                                : null,
                            emptyHint: 'From PDF import',
                          ),
                          SizedBox(width: 68, child: _matchIndicator()),
                          _photoSlot(
                            label: 'Live photo',
                            image: _photoBusy
                                ? const Center(
                                    child: SizedBox(width: 26, height: 26,
                                        child: CircularProgressIndicator(strokeWidth: 2.4)))
                                : (_photoPath != null
                                    ? Image.file(File(_photoPath!),
                                        fit: BoxFit.cover)
                                    : null),
                            emptyHint: 'Use camera below',
                          ),
                        ],
                      ),
                      if (_photoNote != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(_photoNote!,
                              style: TextStyle(
                                  fontSize: 12.5,
                                  fontWeight: FontWeight.w600,
                                  color: Colors.red.shade700)),
                        ),
                      if (_verifyNote != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(_verifyNote!,
                              style: TextStyle(
                                  fontSize: 12,
                                  color: Colors.orange.shade800)),
                        ),
                      const SizedBox(height: 10),
                      Text(
                        'The live photo also enrolls the worker for CAMERA (face) attendance. When both photos exist, they are compared automatically.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      Row(children: [
                        FilledButton.tonalIcon(
                            onPressed: () => _takePhoto(ImageSource.camera),
                            icon: const Icon(Icons.photo_camera),
                            label: Text(_photoPath == null
                                ? 'Take live photo'
                                : 'Retake')),
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

              // ── Registration checklist — live status of every requirement ──
              Card(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Ready to save?',
                          style: Theme.of(context).textTheme.titleSmall),
                      const SizedBox(height: 4),
                      _checkRow(_name.text.trim().isNotEmpty, 'Worker name'),
                      _checkRow(
                          RegExp(r'^\d{12}$').hasMatch(_aadhaar.text.trim()),
                          'Aadhaar — 12 digits'
                          '${_pdfPath != null ? ' + PDF attached' : ''}'),
                      _checkRow(_fp != null,
                          'Fingerprint${_fp != null ? ' — quality ${_fp!.quality}${_fp!.simulated ? ' (simulated)' : ''}' : ''}'),
                      _checkRow(
                          _photoPath != null,
                          'Live photo'
                          '${_faceSim != null ? ' — Aadhaar match ${(_faceSim! * 100).round()}%' : ''}',
                          optional: true),
                      _checkRow(_consent, 'Consent confirmed'),
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

  /// One photo slot: image pops in with a scale animation; dashed-style
  /// placeholder with a hint until then.
  Widget _photoSlot(
      {required String label, Widget? image, required String emptyHint}) {
    return Expanded(
      child: Column(children: [
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 350),
          transitionBuilder: (child, anim) => ScaleTransition(
              scale: CurvedAnimation(parent: anim, curve: Curves.easeOutBack),
              child: FadeTransition(opacity: anim, child: child)),
          child: Container(
            key: ValueKey(image == null ? 'empty-$label' : 'img-$label'),
            height: 120,
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(10),
              color: const Color(0xFF10685A).withValues(alpha: .06),
              border: Border.all(
                  color: image != null
                      ? const Color(0xFF10685A)
                      : Colors.grey.shade400,
                  width: image != null ? 1.6 : 1),
            ),
            child: image ??
                Center(
                    child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                      Icon(Icons.person_outline,
                          color: Colors.grey.shade500, size: 34),
                      const SizedBox(height: 4),
                      Text(emptyHint,
                          textAlign: TextAlign.center,
                          style: TextStyle(
                              fontSize: 10, color: Colors.grey.shade600)),
                    ])),
          ),
        ),
        const SizedBox(height: 5),
        Text(label, style: const TextStyle(fontSize: 11.5)),
      ]),
    );
  }

  /// Animated indicator between the two photos: idle ⇄, spinner while
  /// comparing, green % on match, amber % on low match.
  Widget _matchIndicator() {
    Widget child;
    if (_verifyBusy) {
      child = const SizedBox(
          key: ValueKey('busy'),
          width: 22, height: 22,
          child: CircularProgressIndicator(strokeWidth: 2.4));
    } else if (_faceSim != null) {
      final ok = _faceMatch == true;
      final color = ok ? const Color(0xFF16A34A) : Colors.orange.shade800;
      child = TweenAnimationBuilder<double>(
        key: ValueKey('score-$_faceSim'),
        tween: Tween(begin: 0, end: 1),
        duration: const Duration(milliseconds: 550),
        curve: Curves.elasticOut,
        builder: (context, v, c) => Transform.scale(scale: v, child: c),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(ok ? Icons.verified_user : Icons.help_outline,
              color: color, size: 26),
          Text('${(_faceSim! * 100).round()}%',
              style: TextStyle(
                  fontSize: 13, fontWeight: FontWeight.w800, color: color)),
          Text(ok ? 'match' : 'low match',
              style: TextStyle(fontSize: 9.5, color: color)),
        ]),
      );
    } else {
      child = Icon(Icons.compare_arrows,
          key: const ValueKey('idle'), color: Colors.grey.shade400, size: 26);
    }
    return AnimatedSwitcher(
        duration: const Duration(milliseconds: 250),
        child: Center(child: child));
  }

  /// Checklist row whose icon animates pending → done.
  Widget _checkRow(bool done, String label, {bool optional = false}) {
    final color = done
        ? const Color(0xFF16A34A)
        : (optional ? Colors.grey.shade500 : Colors.orange.shade800);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(children: [
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 300),
          transitionBuilder: (child, anim) =>
              ScaleTransition(scale: anim, child: child),
          child: Icon(
            done
                ? Icons.check_circle
                : (optional
                    ? Icons.radio_button_unchecked
                    : Icons.error_outline),
            key: ValueKey('$label-$done'),
            size: 18,
            color: color,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
            child: Text(
                label + (optional && !done ? '  (optional)' : ''),
                style: TextStyle(
                    fontSize: 12.5,
                    color: done ? null : color,
                    fontWeight: done ? FontWeight.w500 : FontWeight.w400))),
      ]),
    );
  }
}
