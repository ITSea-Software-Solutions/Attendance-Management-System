import 'package:flutter/material.dart';

import '../core/scope.dart';
import 'register_worker.dart';

/// Vendor mode — worker list (offline cache) + offline-capable registration.
class VendorWorkersScreen extends StatefulWidget {
  const VendorWorkersScreen({super.key});
  @override
  State<VendorWorkersScreen> createState() => _VendorWorkersScreenState();
}

class _VendorWorkersScreenState extends State<VendorWorkersScreen> {
  String _search = '';

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: TextField(
              decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search), hintText: 'Search workers'),
              onChanged: (v) => setState(() => _search = v),
            ),
          ),
          Expanded(
            child: FutureBuilder(
              future: app.workers(search: _search),
              builder: (context, snap) {
                final rows = snap.data ?? const [];
                if (snap.connectionState == ConnectionState.done && rows.isEmpty) {
                  return const Center(
                      child: Text('No workers yet — tap Register.'));
                }
                return ListView.separated(
                  itemCount: rows.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final w = rows[i];
                    final state = w['sync_state'] as String;
                    return ListTile(
                      onTap: () => _showDetail(w),
                      leading: CircleAvatar(
                          child: Text((w['name'] as String).isEmpty
                              ? '?'
                              : (w['name'] as String)[0].toUpperCase())),
                      title: Text(w['name'] as String),
                      subtitle: Text(
                          '${w['aadhaar_masked'] ?? 'no Aadhaar'} · ${w['status']}'
                          '${state == 'error' ? '\n⚠ ${w['sync_error']}' : ''}'),
                      isThreeLine: state == 'error',
                      trailing: switch (state) {
                        'pending' => const Tooltip(
                            message: 'Waiting to sync',
                            child: Icon(Icons.cloud_upload, color: Colors.amber)),
                        'error' => const Tooltip(
                            message: 'Sync failed',
                            child: Icon(Icons.error, color: Colors.red)),
                        _ => const Tooltip(
                            message: 'Synced',
                            child: Icon(Icons.cloud_done, color: Colors.teal)),
                      },
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          await Navigator.push(context,
              MaterialPageRoute(builder: (_) => const RegisterWorkerScreen()));
          if (mounted) setState(() {});
        },
        icon: const Icon(Icons.person_add),
        label: const Text('Register'),
      ),
    );
  }

  /// Worker detail: local info + live server analytics + Deploy action —
  /// the same abilities the vendor has on the web portal.
  Future<void> _showDetail(Map<String, Object?> w) async {
    final app = AppScope.of(context);
    final serverId = w['server_id'] as int?;
    await showModalBottomSheet(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              CircleAvatar(
                  radius: 22,
                  child: Text((w['name'] as String? ?? '?').isEmpty
                      ? '?'
                      : (w['name'] as String)[0].toUpperCase())),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${w['name']}',
                          style: Theme.of(context).textTheme.titleMedium),
                      Text(
                          '${w['aadhaar_masked'] ?? ''} · ${w['status']}'
                          '${w['phone'] != null ? ' · ${w['phone']}' : ''}',
                          style: Theme.of(context).textTheme.bodySmall),
                    ]),
              ),
            ]),
            const SizedBox(height: 14),
            if (serverId == null)
              const Text('Not synced to the server yet — analytics and deploy unlock after sync.')
            else if (!app.online)
              const Text('Offline — analytics and deploy need internet.')
            else
              FutureBuilder<Map<String, dynamic>>(
                future: app.workerStats(serverId),
                builder: (context, snap) {
                  if (!snap.hasData) {
                    return const Padding(
                        padding: EdgeInsets.symmetric(vertical: 12),
                        child: Center(
                            child: SizedBox(
                                width: 22, height: 22,
                                child: CircularProgressIndicator(strokeWidth: 2))));
                  }
                  final s = snap.data!;
                  final t = Map<String, dynamic>.from(
                      s['totals'] as Map? ?? s['stats'] as Map? ?? s);
                  String v(List<String> keys) {
                    for (final k in keys) {
                      if (t[k] != null) return '${t[k]}';
                    }
                    return '—';
                  }
                  Widget stat(String label, String value) => Expanded(
                        child: Column(children: [
                          Text(value,
                              style: Theme.of(context)
                                  .textTheme
                                  .titleLarge
                                  ?.copyWith(fontWeight: FontWeight.w700)),
                          Text(label,
                              style: Theme.of(context).textTheme.bodySmall),
                        ]),
                      );
                  return Row(children: [
                    stat('Days present', v(['days_present', 'present_days', 'days'])),
                    stat('Total hours', v(['total_hours', 'hours'])),
                    stat('Missed OUT', v(['missed_out', 'missing_out', 'missed_outs'])),
                  ]);
                },
              ),
            const SizedBox(height: 16),
            Row(children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: serverId != null && app.online
                      ? () {
                          Navigator.pop(context);
                          _deployDialog(w, serverId);
                        }
                      : null,
                  icon: const Icon(Icons.assignment_turned_in),
                  label: const Text('Deploy to company'),
                ),
              ),
            ]),
          ],
        ),
      ),
    );
  }

  /// Deploy = assign to a company for a date range (POST /assignments).
  Future<void> _deployDialog(Map<String, Object?> w, int serverId) async {
    final app = AppScope.of(context);
    List<Map<String, dynamic>> companies;
    try {
      companies = await app.approvedCompanies();
    } catch (_) {
      companies = const [];
    }
    if (!mounted) return;
    if (companies.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text(
              'No approved companies yet — request access on the web portal first.')));
      return;
    }
    int? companyId = companies.first['id'] as int?;
    var start = DateTime.now();
    var end = DateTime.now().add(const Duration(days: 30));
    String d(DateTime x) =>
        '${x.year}-${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}';

    final go = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setD) => AlertDialog(
          title: Text('Deploy ${w['name']}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<int>(
              initialValue: companyId,
              decoration: const InputDecoration(labelText: 'Company'),
              items: [
                for (final c in companies)
                  DropdownMenuItem(
                      value: c['id'] as int?, child: Text('${c['name']}')),
              ],
              onChanged: (v) => companyId = v,
            ),
            const SizedBox(height: 8),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text('From  ${d(start)}'),
              trailing: const Icon(Icons.edit_calendar, size: 18),
              onTap: () async {
                final p = await showDatePicker(
                    context: context,
                    initialDate: start,
                    firstDate: DateTime.now().subtract(const Duration(days: 30)),
                    lastDate: DateTime.now().add(const Duration(days: 365)));
                if (p != null) setD(() => start = p);
              },
            ),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text('To      ${d(end)}'),
              trailing: const Icon(Icons.edit_calendar, size: 18),
              onTap: () async {
                final p = await showDatePicker(
                    context: context,
                    initialDate: end,
                    firstDate: start,
                    lastDate: DateTime.now().add(const Duration(days: 730)));
                if (p != null) setD(() => end = p);
              },
            ),
          ]),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel')),
            FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Deploy')),
          ],
        ),
      ),
    );
    if (go != true || companyId == null) return;
    try {
      final msg = await app.deployWorker(
          workerServerId: serverId,
          companyId: companyId!,
          startDate: d(start),
          endDate: d(end));
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(msg)));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(e.toString().contains('422')
                ? 'Deploy rejected — overlapping assignment or plan limit reached.'
                : 'Deploy failed — check the connection and retry.')));
      }
    }
  }
}
