import 'package:flutter/material.dart';

import '../biometric/driver.dart';
import '../core/scope.dart';
import 'gate_result.dart';
import 'silent_snap.dart';

/// Gate mode — fingerprint identify → confirm IN/OUT. Works fully offline
/// (SIM identify against the locally-cached deployed workers; marks queue).
class GateAttendanceScreen extends StatefulWidget {
  const GateAttendanceScreen({super.key});
  @override
  State<GateAttendanceScreen> createState() => _GateAttendanceScreenState();
}

class _GateAttendanceScreenState extends State<GateAttendanceScreen> {
  bool _scanning = false;
  String? _status;

  Future<void> _scan() async {
    final app = AppScope.of(context);
    setState(() {
      _scanning = true;
      _status = 'Place finger on scanner…';
    });
    try {
      final candidates = await app.deployedWorkers();
      if (candidates.isEmpty) {
        setState(() =>
            _status = 'No active workers deployed here today. Sync first?');
        return;
      }
      final driver = await BiometricDriver.best();
      var result = await driver.identify(candidates);
      if (result == null && driver is SgibiosrvDriver) {
        // Real capture succeeded but on-device matching isn't in v0.9:
        // record the capture and let the operator confirm the worker.
        final picked = await _pickWorker(candidates);
        if (picked == null) return;
        result = IdentifyResult(picked, 0, simulated: false);
      }
      if (result == null) {
        setState(() => _status = 'Capture failed — try again.');
        return;
      }
      if (!mounted) return;
      await _confirm(result);
    } finally {
      if (mounted) {
        setState(() => _scanning = false);
      }
    }
  }

  Future<Map<String, Object?>?> _pickWorker(
      List<Map<String, Object?>> candidates) async {
    return showModalBottomSheet<Map<String, Object?>>(
      context: context,
      builder: (context) => ListView(
        children: [
          const Padding(
            padding: EdgeInsets.all(16),
            child: Text('Capture recorded — select the worker:',
                style: TextStyle(fontWeight: FontWeight.bold)),
          ),
          for (final w in candidates)
            ListTile(
              leading: const Icon(Icons.person),
              title: Text(w['name'] as String),
              subtitle: Text('${w['aadhaar_masked'] ?? ''}'),
              onTap: () => Navigator.pop(context, w),
            ),
        ],
      ),
    );
  }

  Future<void> _confirm(IdentifyResult result) async {
    final app = AppScope.of(context);
    final worker = result.worker;
    // Fingerprint verified → quietly photograph the person at the gate while
    // the operator confirms. Proof attaches to the mark; never blocks it.
    final proofFuture = silentSnap();
    final suggested = await app.nextTypeFor(worker['server_id'] as int? ?? -1);
    if (!mounted) return;
    final type = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(worker['name'] as String),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('${worker['aadhaar_masked'] ?? ''}'),
            const SizedBox(height: 6),
            Text(result.simulated
                ? 'Simulated match · score ${result.score}/200'
                : 'Fingerprint captured'),
            const SizedBox(height: 12),
            Text('Mark $suggested?',
                style: Theme.of(context).textTheme.titleMedium),
          ],
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel')),
          if (suggested == 'OUT')
            TextButton(
                onPressed: () => Navigator.pop(context, 'IN'),
                child: const Text('IN instead')),
          if (suggested == 'IN')
            TextButton(
                onPressed: () => Navigator.pop(context, 'OUT'),
                child: const Text('OUT instead')),
          FilledButton(
              onPressed: () => Navigator.pop(context, suggested),
              child: Text('Mark $suggested')),
        ],
      ),
    );
    if (type == null) return;
    final proofPath = await proofFuture; // usually already done
    await app.markAttendance(
      worker: worker,
      type: type,
      method: 'fingerprint',
      score: result.score,
      simulated: result.simulated,
      proofPath: proofPath,
    );
    if (mounted) {
      setState(() => _status = null);
      // The "verified" moment: check + all three photos + greeting.
      await showGateResult(
        context,
        name: worker['name'] as String,
        type: type,
        workerServerId: worker['server_id'] as int?,
        score: result.score,
        simulated: result.simulated,
        queuedOffline: !app.online,
        proofPath: proofPath,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            InkWell(
              borderRadius: BorderRadius.circular(120),
              onTap: _scanning ? null : _scan,
              child: Container(
                width: 180,
                height: 180,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: const Color(0xFF10685A).withValues(alpha: .1),
                  border: Border.all(color: const Color(0xFF10685A), width: 3),
                ),
                child: _scanning
                    ? const Padding(
                        padding: EdgeInsets.all(60),
                        child: CircularProgressIndicator())
                    : const Icon(Icons.fingerprint,
                        size: 110, color: Color(0xFF10685A)),
              ),
            ),
            const SizedBox(height: 18),
            Text(_scanning ? 'Scanning…' : 'Tap to scan',
                style: Theme.of(context).textTheme.titleLarge),
            if (_status != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 10, 24, 0),
                child: Text(_status!, textAlign: TextAlign.center),
              ),
            const SizedBox(height: 10),
            Text(
              app.user?['location_name'] != null
                  ? 'Gate: ${app.user?['location_name']}'
                  : 'Main gate',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

/// Workers currently inside (last event IN) — from local data.
class ExceptionsScreen extends StatelessWidget {
  const ExceptionsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return FutureBuilder(
      future: app.currentlyInside(),
      builder: (context, snap) {
        final rows = snap.data ?? const [];
        if (snap.connectionState == ConnectionState.done && rows.isEmpty) {
          return const Center(child: Text('Nobody is currently inside.'));
        }
        return ListView.separated(
          itemCount: rows.length,
          separatorBuilder: (_, __) => const Divider(height: 1),
          itemBuilder: (context, i) {
            final r = rows[i];
            return ListTile(
              leading: const Icon(Icons.login, color: Colors.teal),
              title: Text('${r['worker_name']}'),
              subtitle: Text('IN since ${fmt(r['last_at'])}'),
            );
          },
        );
      },
    );
  }

  static String fmt(Object? iso) {
    final d = DateTime.tryParse('${iso ?? ''}');
    if (d == null) return '?';
    two(int n) => n.toString().padLeft(2, '0');
    return '${two(d.day)}/${two(d.month)} ${two(d.hour)}:${two(d.minute)}';
  }
}
