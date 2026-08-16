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

}
