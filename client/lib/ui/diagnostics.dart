import 'package:flutter/material.dart';

import '../biometric/driver.dart';
import '../core/scope.dart';

/// Diagnostics — live status of everything that can break in the field:
/// the server connection, the fingerprint scanner, and the offline sync
/// queue. Every row has Retry; the scanner card also offers a test capture.
class DiagnosticsScreen extends StatefulWidget {
  const DiagnosticsScreen({super.key});
  @override
  State<DiagnosticsScreen> createState() => _DiagnosticsScreenState();
}

class _DiagnosticsScreenState extends State<DiagnosticsScreen> {
  Map<String, Object?>? _server;
  DeviceProbe? _scanner;
  bool _serverBusy = false, _scannerBusy = false, _capBusy = false;
  EnrollCapture? _testCap;
  String? _capError;

  @override
  void initState() {
    super.initState();
    _probeServer();
    _probeScanner();
  }

  Future<void> _probeServer() async {
    setState(() => _serverBusy = true);
    final r = await AppScope.of(context).serverProbe();
    if (mounted) setState(() { _server = r; _serverBusy = false; });
  }

  Future<void> _probeScanner() async {
    setState(() => _scannerBusy = true);
    final r = await BiometricDriver.probeScanner();
    if (mounted) setState(() { _scanner = r; _scannerBusy = false; });
  }

  Future<void> _testCapture() async {
    setState(() { _capBusy = true; _capError = null; _testCap = null; });
    EnrollCapture? r;
    String? err;
    r = await BiometricDriver.enrollCapture();
    err = BiometricDriver.lastEnrollError;
    if (mounted) {
      setState(() {
        _testCap = r;
        _capBusy = false;
        if (r == null) _capError = err ?? 'Capture failed — check the scanner and retry.';
      });
    }
  }

  Widget _statusIcon(bool? ok, bool busy) {
    if (busy) {
      return const SizedBox(
          width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2));
    }
    if (ok == null) return const Icon(Icons.help_outline, color: Colors.grey);
    return ok
        ? const Icon(Icons.check_circle, color: Colors.teal)
        : const Icon(Icons.error, color: Colors.red);
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Diagnostics')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              // ── Server ────────────────────────────────────────────────────
              Card(
                child: ListTile(
                  leading: _statusIcon(_server?['ok'] as bool?, _serverBusy),
                  title: const Text('Server connection'),
                  subtitle: Text(_server == null
                      ? 'Checking…'
                      : (_server!['ok'] == true
                          ? '${_server!['server']} · ${_server!['ms']} ms round-trip'
                          : '${_server!['server']}\n${_server!['error']}')),
                  isThreeLine: _server != null && _server!['ok'] != true,
                  trailing: IconButton(
                      tooltip: 'Retry',
                      icon: const Icon(Icons.refresh),
                      onPressed: _serverBusy ? null : _probeServer),
                ),
              ),

              // ── Scanner ───────────────────────────────────────────────────
              Card(
                child: Column(children: [
                  ListTile(
                    leading: _statusIcon(_scanner?.ok, _scannerBusy),
                    title: const Text('Fingerprint scanner'),
                    subtitle: Text(_scanner == null
                        ? 'Checking…'
                        : '${_scanner!.detail}${_scanner!.latencyMs != null ? ' (${_scanner!.latencyMs} ms)' : ''}'),
                    isThreeLine: true,
                    trailing: IconButton(
                        tooltip: 'Rescan',
                        icon: const Icon(Icons.refresh),
                        onPressed: _scannerBusy ? null : _probeScanner),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                    child: Row(children: [
                      FilledButton.tonalIcon(
                        onPressed: _capBusy ? null : _testCapture,
                        icon: _capBusy
                            ? const SizedBox(
                                width: 14, height: 14,
                                child: CircularProgressIndicator(strokeWidth: 2))
                            : const Icon(Icons.fingerprint),
                        label: Text(_capBusy ? 'Capturing…' : 'Test capture'),
                      ),
                      const SizedBox(width: 10),
                      if (_testCap != null)
                        Chip(
                          avatar: const Icon(Icons.check, size: 14, color: Colors.teal),
                          label: Text(
                              'Quality ${_testCap!.quality}${_testCap!.simulated ? " · SIMULATED" : " · real device"}'),
                        ),
                      if (_capError != null)
                        Expanded(
                            child: Text(_capError!,
                                style: const TextStyle(color: Colors.red, fontSize: 12))),
                    ]),
                  ),
                ]),
              ),

              // ── Sync ──────────────────────────────────────────────────────
              Card(
                child: ListTile(
                  leading: _statusIcon(app.pendingCount == 0, app.syncing),
                  title: const Text('Offline sync queue'),
                  subtitle: Text(
                      '${app.pendingCount} pending record(s) · last sync: ${app.lastSyncAt?.replaceFirst("T", " ").substring(0, 16) ?? "never"}'
                      '${app.online ? "" : "\nDevice is OFFLINE — records will sync when connectivity returns."}'),
                  isThreeLine: !app.online,
                  trailing: IconButton(
                    tooltip: 'Sync now',
                    icon: const Icon(Icons.sync),
                    onPressed: app.syncing
                        ? null
                        : () async {
                            final err = await app.sync();
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                                  content: Text(err == null
                                      ? 'Synced.'
                                      : 'Sync failed — still offline; queue kept.')));
                              setState(() {});
                            }
                          },
                  ),
                ),
              ),

              const SizedBox(height: 8),
              Text(
                'Tip: on Windows gate stations, the SecuGen scanner needs its driver + the SGIBIOSRV service running. On Android, fingerprint is simulated until the USB-OTG SDK driver ships — camera (face) attendance works everywhere.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
