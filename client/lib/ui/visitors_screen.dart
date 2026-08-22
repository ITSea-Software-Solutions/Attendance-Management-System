import 'package:flutter/material.dart';

import '../core/scope.dart';
import 'camera_capture.dart';

/// Visitors / gate passes — the gate's companion to worker attendance.
/// Create a pass (guest + live photo + host to meet); the host is asked on
/// WhatsApp (YES/NO) when configured — otherwise the gate records the
/// host's phone answer as a manual decision with a note. Online-only.
class VisitorsScreen extends StatefulWidget {
  const VisitorsScreen({super.key});
  @override
  State<VisitorsScreen> createState() => _VisitorsScreenState();
}

class _VisitorsScreenState extends State<VisitorsScreen> {
  List<Map<String, dynamic>> _passes = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final app = AppScope.of(context);
    if (!app.online) {
      setState(() { _loading = false; _error = 'Visitor passes need internet.'; });
      return;
    }
    try {
      final rows = await app.gatePasses();
      if (mounted) setState(() { _passes = rows; _loading = false; _error = null; });
    } catch (_) {
      if (mounted) setState(() { _loading = false; _error = 'Could not load passes.'; });
    }
  }

  (Color, IconData, String) _statusLook(Map<String, dynamic> p) {
    if (p['exit_at'] != null) return (Colors.grey, Icons.logout, 'left');
    if (p['entry_at'] != null) return (Colors.teal, Icons.login, 'inside');
    return switch ('${p['status']}') {
      'approved' => (Colors.green, Icons.check_circle, 'approved'),
      'denied' => (Colors.red, Icons.block, 'denied'),
      'expired' => (Colors.grey, Icons.timer_off, 'expired'),
      _ => (Colors.amber, Icons.hourglass_top, 'waiting for host'),
    };
  }

  Future<void> _act(Future<Map<String, dynamic>> Function() action,
      String fallback) async {
    String? msg;
    try {
      await action();
    } catch (e) {
      msg = AppState.apiMessage(e, fallback);
    }
    if (!mounted) return;
    if (msg != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    }
    await _load();
  }

  Future<void> _decide(Map<String, dynamic> p, String decision) async {
    final app = AppScope.of(context);
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('${decision == 'approved' ? 'Allow' : 'Deny'} ${p['guest_name']}?'),
        content: TextField(
          controller: note,
          autofocus: true,
          decoration: const InputDecoration(
            labelText: 'How did the host respond?',
            helperText: 'e.g. "Confirmed on phone call" — recorded in the audit trail.',
            helperMaxLines: 2,
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: Text(decision == 'approved' ? 'Allow visitor' : 'Deny')),
        ],
      ),
    );
    if (ok != true) return;
    if (note.text.trim().isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('A note is required — say how the host responded.')));
      }
      return;
    }
    await _act(() => app.decideGatePass(p['id'] as int, decision, note.text.trim()),
        'Could not record the decision.');
  }

  Future<void> _newPass() async {
    final app = AppScope.of(context);
    List<Map<String, dynamic>> hosts;
    try {
      hosts = await app.visitorHosts();
    } catch (_) {
      hosts = const [];
    }
    if (!mounted) return;
    if (hosts.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'No visitor hosts yet — HR adds them on the portal (Visitors → Hosts).')));
      return;
    }
    final name = TextEditingController();
    final phone = TextEditingController();
    final purpose = TextEditingController();
    int? hostId = hosts.first['id'] as int?;
    String? photoPath;

    final go = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setD) => AlertDialog(
          title: const Text('New gate pass'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(
                  controller: name,
                  decoration: const InputDecoration(labelText: 'Guest name *')),
              TextField(
                  controller: phone,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'Guest phone')),
              TextField(
                  controller: purpose,
                  decoration: const InputDecoration(labelText: 'Purpose')),
              const SizedBox(height: 10),
              DropdownButtonFormField<int>(
                initialValue: hostId,
                decoration: const InputDecoration(labelText: 'Meeting whom? *'),
                items: [
                  for (final h in hosts)
                    DropdownMenuItem(
                      value: h['id'] as int?,
                      child: Text(
                          '${h['name']}${h['department'] != null ? ' · ${h['department']}' : ''}',
                          overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => hostId = v,
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: () async {
                  final shot = await captureWithCamera(context);
                  if (shot != null) setD(() => photoPath = shot);
                },
                icon: Icon(photoPath == null ? Icons.photo_camera : Icons.check_circle,
                    size: 18, color: photoPath == null ? null : Colors.teal),
                label: Text(photoPath == null ? 'Take guest photo' : 'Photo taken'),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
            FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Create pass')),
          ],
        ),
      ),
    );
    if (go != true) return;
    if (name.text.trim().isEmpty || hostId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Guest name and host are required.')));
      }
      return;
    }
    await _act(
        () => app.createGatePass(
              hostId: hostId!,
              guestName: name.text.trim(),
              guestPhone: phone.text.trim(),
              purpose: purpose.text.trim(),
              photoPath: photoPath,
            ),
        'Could not create the pass.');
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Pass created — the host has been asked. Allow entry once approved.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? ListView(children: [
                    Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(_error!, textAlign: TextAlign.center))
                  ])
                : _passes.isEmpty
                    ? ListView(children: const [
                        Padding(
                          padding: EdgeInsets.all(32),
                          child: Text(
                              'No visitor passes today.\nTap "New pass" when a guest arrives.',
                              textAlign: TextAlign.center),
                        )
                      ])
                    : ListView.separated(
                        itemCount: _passes.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, i) {
                          final p = _passes[i];
                          final (color, icon, label) = _statusLook(p);
                          final pending = p['status'] == 'pending';
                          final canEnter = p['status'] == 'approved' &&
                              p['entry_at'] == null;
                          final canExit =
                              p['entry_at'] != null && p['exit_at'] == null;
                          return ListTile(
                            leading: Icon(icon, color: color),
                            title: Text('${p['guest_name']}'),
                            subtitle: Text(
                              '${p['code']} · meets ${p['host']?['name'] ?? '?'}'
                              '${p['purpose'] != null ? ' · ${p['purpose']}' : ''}\n'
                              '$label${p['decided_via'] == 'whatsapp' ? ' (WhatsApp)' : ''}',
                              maxLines: 2,
                            ),
                            isThreeLine: true,
                            trailing: Wrap(spacing: 4, children: [
                              if (pending) ...[
                                IconButton(
                                    tooltip: 'Host said yes',
                                    onPressed: () => _decide(p, 'approved'),
                                    icon: const Icon(Icons.check_circle,
                                        color: Colors.green)),
                                IconButton(
                                    tooltip: 'Host said no',
                                    onPressed: () => _decide(p, 'denied'),
                                    icon:
                                        const Icon(Icons.cancel, color: Colors.red)),
                              ],
                              if (canEnter)
                                TextButton(
                                    onPressed: () => _act(
                                        () => app.moveGatePass(
                                            p['id'] as int, 'entry'),
                                        'Could not record entry.'),
                                    child: const Text('Entry')),
                              if (canExit)
                                TextButton(
                                    onPressed: () => _act(
                                        () =>
                                            app.moveGatePass(p['id'] as int, 'exit'),
                                        'Could not record exit.'),
                                    child: const Text('Exit')),
                            ]),
                          );
                        },
                      ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newPass,
        icon: const Icon(Icons.badge),
        label: const Text('New pass'),
      ),
    );
  }
}
