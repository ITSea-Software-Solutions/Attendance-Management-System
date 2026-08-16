import 'dart:async';

import 'package:flutter/material.dart';

import '../core/scope.dart';

/// Full-screen "verified" moment after a successful gate mark: animated
/// check, worker photo (authenticated fetch; initials avatar offline),
/// name, IN/OUT and a greeting. Auto-dismisses so the gate keeps moving.
Future<void> showGateResult(
  BuildContext context, {
  required String name,
  required String type, // IN | OUT
  int? workerServerId,
  String method = 'fingerprint',
  int? score,
  bool simulated = false,
  bool queuedOffline = false,
}) async {
  final app = AppScope.of(context);
  final photoReq =
      app.online ? await app.workerPhotoRequest(workerServerId) : null;
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
    required this.method,
    this.score,
    required this.simulated,
    required this.queuedOffline,
  });

  final String name;
  final String type;
  final String? photoUrl;
  final Map<String, String>? photoHeaders;
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
    _closer = Timer(const Duration(milliseconds: 3800), () {
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

  @override
  Widget build(BuildContext context) {
    final isIn = widget.type == 'IN';
    final color = isIn ? const Color(0xFF16A34A) : const Color(0xFFEA8C00);
    final now = TimeOfDay.now().format(context);
    final initials = widget.name.trim().isEmpty
        ? '?'
        : widget.name
            .trim()
            .split(RegExp(r'\s+'))
            .take(2)
            .map((w) => w[0].toUpperCase())
            .join();

    return Material(
      type: MaterialType.transparency,
      child: SafeArea(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => Navigator.of(context).maybePop(),
          child: Center(
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
                    width: 96,
                    height: 96,
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
                        size: 64, color: Colors.white),
                  ),
                ),
                const SizedBox(height: 26),
                // Photo (server, authenticated) or initials
                CircleAvatar(
                  radius: 56,
                  backgroundColor: Colors.white24,
                  foregroundImage: widget.photoUrl != null
                      ? NetworkImage(widget.photoUrl!,
                          headers: widget.photoHeaders)
                      : null,
                  onForegroundImageError:
                      widget.photoUrl != null ? (_, __) {} : null,
                  child: Text(initials,
                      style: const TextStyle(
                          fontSize: 34,
                          color: Colors.white,
                          fontWeight: FontWeight.bold)),
                ),
                const SizedBox(height: 18),
                Text(widget.name,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 26,
                        fontWeight: FontWeight.w700,
                        color: Colors.white)),
                const SizedBox(height: 6),
                Text(_greeting,
                    style: const TextStyle(
                        fontSize: 15, color: Colors.white70)),
                const SizedBox(height: 18),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                  decoration: BoxDecoration(
                      color: color.withValues(alpha: .18),
                      border: Border.all(color: color, width: 1.4),
                      borderRadius: BorderRadius.circular(30)),
                  child: Text(
                    '${widget.type}  ·  $now',
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w700,
                        color: color,
                        letterSpacing: 1.1),
                  ),
                ),
                const SizedBox(height: 14),
                Text(
                  [
                    if (widget.method == 'face')
                      'Verified by face'
                    else
                      'Verified by fingerprint${widget.score != null ? ' · score ${widget.score}' : ''}',
                    if (widget.simulated) 'SIMULATED',
                    if (widget.queuedOffline) 'queued — will sync when online',
                  ].join('  ·  '),
                  style: const TextStyle(fontSize: 12, color: Colors.white54),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
