import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import '../core/scope.dart';

/// Full-screen "verified" moment after a successful gate mark: animated
/// check, ALL THREE photos (Aadhaar document · registration · this gate
/// capture), the worker's name, a directional IN/OUT animation and a
/// greeting. Auto-dismisses so the gate keeps moving.
Future<void> showGateResult(
  BuildContext context, {
  required String name,
  required String type, // IN | OUT
  int? workerServerId,
  String method = 'fingerprint',
  int? score,
  bool simulated = false,
  bool queuedOffline = false,
  String? proofPath, // gate camera capture (local file)
}) async {
  final app = AppScope.of(context);
  final photoReq =
      app.online ? await app.workerPhotoRequest(workerServerId) : null;
  final aadhaarReq =
      app.online ? await app.aadhaarPhotoRequest(workerServerId) : null;
  if (!context.mounted) return;
  await showGeneralDialog(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'verified',
    barrierColor: Colors.black87,
    transitionDuration: const Duration(milliseconds: 250),
    pageBuilder: (context, _, __) => _GateResultPage(
      name: name,
      type: type,
      photoUrl: photoReq?.$1,
      photoHeaders: photoReq?.$2,
      aadhaarUrl: aadhaarReq?.$1,
      aadhaarHeaders: aadhaarReq?.$2,
      proofPath: proofPath,
      method: method,
      score: score,
      simulated: simulated,
      queuedOffline: queuedOffline,
    ),
    transitionBuilder: (context, anim, _, child) => FadeTransition(
      opacity: anim,
      child: ScaleTransition(
          scale: Tween(begin: .92, end: 1.0).animate(
              CurvedAnimation(parent: anim, curve: Curves.easeOutBack)),
          child: child),
    ),
  );
}

class _GateResultPage extends StatefulWidget {
  const _GateResultPage({
    required this.name,
    required this.type,
    this.photoUrl,
    this.photoHeaders,
    this.aadhaarUrl,
    this.aadhaarHeaders,
    this.proofPath,
    required this.method,
    this.score,
    required this.simulated,
    required this.queuedOffline,
  });

  final String name;
  final String type;
  final String? photoUrl;
  final Map<String, String>? photoHeaders;
  final String? aadhaarUrl;
  final Map<String, String>? aadhaarHeaders;
  final String? proofPath;
  final String method;
  final int? score;
  final bool simulated;
  final bool queuedOffline;

  @override
  State<_GateResultPage> createState() => _GateResultPageState();
}

class _GateResultPageState extends State<_GateResultPage> {
  Timer? _closer;

  @override
  void initState() {
    super.initState();
    _closer = Timer(const Duration(milliseconds: 4200), () {
      if (mounted) Navigator.of(context).maybePop();
    });
  }

  @override
  void dispose() {
    _closer?.cancel();
    super.dispose();
  }

  String get _greeting {
    final h = DateTime.now().hour;
    if (widget.type == 'OUT') return 'Goodbye, see you tomorrow!';
    if (h < 12) return 'Good morning, welcome in!';
    if (h < 17) return 'Good afternoon, welcome in!';
    return 'Good evening, welcome in!';
  }

  String get _initials => widget.name.trim().isEmpty
      ? '?'
      : widget.name
          .trim()
          .split(RegExp(r'\s+'))
          .take(2)
          .map((w) => w[0].toUpperCase())
          .join();

  /// One labeled photo tile; placeholder initials when the source is missing.
  Widget _photoTile(String label, {ImageProvider? image}) {
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Container(
        width: 88,
        height: 106,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          color: Colors.white12,
          border: Border.all(color: Colors.white24),
          image: image != null
              ? DecorationImage(image: image, fit: BoxFit.cover)
              : null,
        ),
        child: image == null
            ? Center(
                child: Text(_initials,
                    style: const TextStyle(
                        fontSize: 24,
                        color: Colors.white54,
                        fontWeight: FontWeight.bold)))
            : null,
      ),
      const SizedBox(height: 5),
      Text(label,
          style: const TextStyle(fontSize: 10.5, color: Colors.white60)),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final isIn = widget.type == 'IN';
    final color = isIn ? const Color(0xFF16A34A) : const Color(0xFFEA8C00);
    final now = TimeOfDay.now().format(context);

    return Material(
      type: MaterialType.transparency,
      child: SafeArea(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => Navigator.of(context).maybePop(),
          child: Center(
            child: SingleChildScrollView(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  // Animated check badge
                  TweenAnimationBuilder<double>(
                    tween: Tween(begin: 0, end: 1),
                    duration: const Duration(milliseconds: 650),
                    curve: Curves.elasticOut,
                    builder: (context, v, child) =>
                        Transform.scale(scale: v, child: child),
                    child: Container(
                      width: 84,
                      height: 84,
                      decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: color,
                          boxShadow: [
                            BoxShadow(
                                color: color.withValues(alpha: .55),
                                blurRadius: 42,
                                spreadRadius: 6)
                          ]),
                      child: const Icon(Icons.check_rounded,
                          size: 56, color: Colors.white),
                    ),
                  ),
                  const SizedBox(height: 20),
                  // All three photos: document · registration · this capture
                  Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                    _photoTile('Aadhaar',
                        image: widget.aadhaarUrl != null
                            ? NetworkImage(widget.aadhaarUrl!,
                                headers: widget.aadhaarHeaders)
                            : null),
                    const SizedBox(width: 10),
                    _photoTile('Registered',
                        image: widget.photoUrl != null
                            ? NetworkImage(widget.photoUrl!,
                                headers: widget.photoHeaders)
                            : null),
                    const SizedBox(width: 10),
                    _photoTile('At gate now',
                        image: widget.proofPath != null
                            ? FileImage(File(widget.proofPath!))
                            : null),
                  ]),
                  const SizedBox(height: 16),
                  Text(widget.name,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          fontSize: 25,
                          fontWeight: FontWeight.w700,
                          color: Colors.white)),
                  const SizedBox(height: 4),
                  Text(_greeting,
                      style:
                          const TextStyle(fontSize: 15, color: Colors.white70)),
                  const SizedBox(height: 16),
                  // Directional IN/OUT animation: arrow slides in (IN, →|) or
                  // out (OUT, |→) beside the big type + time chip.
                  TweenAnimationBuilder<double>(
                    tween: Tween(begin: 0, end: 1),
                    duration: const Duration(milliseconds: 700),
                    curve: Curves.easeOutCubic,
                    builder: (context, v, _) {
                      final dx = isIn ? (1 - v) * -46 : v * 46 - 46;
                      return Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 20, vertical: 10),
                        decoration: BoxDecoration(
                            color: color.withValues(alpha: .18),
                            border: Border.all(color: color, width: 1.4),
                            borderRadius: BorderRadius.circular(30)),
                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                          ClipRect(
                            child: Transform.translate(
                              offset: Offset(dx, 0),
                              child: Icon(
                                  isIn
                                      ? Icons.arrow_forward_rounded
                                      : Icons.arrow_forward_rounded,
                                  color: color,
                                  size: 26),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Text(
                            '${widget.type}  ·  $now',
                            style: TextStyle(
                                fontSize: 19,
                                fontWeight: FontWeight.w800,
                                color: color,
                                letterSpacing: 1.1),
                          ),
                        ]),
                      );
                    },
                  ),
                  const SizedBox(height: 14),
                  Text(
                    [
                      if (widget.method == 'face')
                        'Verified by face'
                      else
                        'Verified by fingerprint${widget.score != null ? ' · score ${widget.score}' : ''}',
                      if (widget.simulated) 'SIMULATED',
                      if (widget.queuedOffline) 'queued — syncs when online',
                    ].join('  ·  '),
                    style:
                        const TextStyle(fontSize: 12, color: Colors.white54),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
