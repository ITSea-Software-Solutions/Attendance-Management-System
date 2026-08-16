import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/scope.dart';

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
        onPressed: () => _register(context),
        icon: const Icon(Icons.person_add),
        label: const Text('Register'),
      ),
    );
  }

  Future<void> _register(BuildContext context) async {
    final app = AppScope.of(context);
    final name = TextEditingController();
    final aadhaar = TextEditingController();
    final phone = TextEditingController();
    String? gender;
    String? error;

    await showDialog<void>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setDialog) => AlertDialog(
          title: const Text('Register worker'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                    controller: name,
                    decoration: const InputDecoration(labelText: 'Full name *')),
                const SizedBox(height: 10),
                TextField(
                  controller: aadhaar,
                  keyboardType: TextInputType.number,
                  maxLength: 12,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(
                      labelText: 'Aadhaar number * (12 digits)',
                      helperText:
                          'Mandatory. Only the last 4 digits are stored.'),
                ),
                TextField(
                    controller: phone,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Phone')),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  initialValue: gender,
                  decoration: const InputDecoration(labelText: 'Gender'),
                  items: const [
                    DropdownMenuItem(value: 'M', child: Text('Male')),
                    DropdownMenuItem(value: 'F', child: Text('Female')),
                    DropdownMenuItem(value: 'O', child: Text('Other')),
                  ],
                  onChanged: (v) => gender = v,
                ),
                if (error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text(error!,
                        style:
                            const TextStyle(color: Colors.red, fontSize: 13)),
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Cancel')),
            FilledButton(
              onPressed: () async {
                if (name.text.trim().isEmpty) {
                  setDialog(() => error = 'Name is required.');
                  return;
                }
                final err = await app.registerWorker(
                  name: name.text.trim(),
                  aadhaar: aadhaar.text.trim(),
                  phone: phone.text.trim().isEmpty ? null : phone.text.trim(),
                  gender: gender,
                );
                if (err != null) {
                  setDialog(() => error = err);
                  return;
                }
                if (context.mounted) {
                  Navigator.pop(context);
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                      content: Text(
                          'Worker saved. Fingerprint enrollment: web portal (app enrollment comes with the scanner SDK).')));
                }
              },
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
    if (mounted) setState(() {});
  }
}
