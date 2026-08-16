import 'package:flutter/material.dart';

import '../core/scope.dart';
import 'diagnostics.dart';
import 'gate_attendance.dart';
import 'vendor_workers.dart';

/// Role-aware shell: tabs depend on who is signed in.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);

    final tabs = <({String label, IconData icon, Widget body})>[
      if (app.isVendor)
        (label: 'Workers', icon: Icons.groups, body: const VendorWorkersScreen()),
      if (app.canMark)
        (
          label: 'Attendance',
          icon: Icons.fingerprint,
          body: const GateAttendanceScreen()
        ),
      (label: 'Inside', icon: Icons.meeting_room, body: const ExceptionsScreen()),
      (label: 'Activity', icon: Icons.receipt_long, body: const _ActivityScreen()),
    ];
    final tab = _tab.clamp(0, tabs.length - 1);

    return Scaffold(
      appBar: AppBar(
        title: Text('TrueCrew — ${_roleLabel(app.role)}'),
        actions: [
          // Sync status chip
          Padding(
            padding: const EdgeInsets.only(right: 4),
            child: Center(
              child: InkWell(
                onTap: app.syncing
                    ? null
                    : () async {
                        final err = await app.sync();
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                              content: Text(err == null
                                  ? 'Synced.'
                                  : 'Sync failed — working offline. Changes stay queued.')));
                        }
                      },
                child: Chip(
                  avatar: app.syncing
                      ? const SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : Icon(
                          app.online ? Icons.cloud_done : Icons.cloud_off,
                          size: 16,
                          color: app.online ? Colors.teal : Colors.amber,
                        ),
                  label: Text(app.pendingCount > 0
                      ? '${app.pendingCount} pending'
                      : (app.online ? 'online' : 'offline')),
                ),
              ),
            ),
          ),
          IconButton(
            tooltip: 'Diagnostics — device & connection status',
            icon: const Icon(Icons.troubleshoot),
            onPressed: () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => const DiagnosticsScreen())),
          ),
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: () async {
              final yes = await showDialog<bool>(
                context: context,
                builder: (context) => AlertDialog(
                  title: const Text('Sign out?'),
                  content: Text(app.pendingCount > 0
                      ? 'WARNING: ${app.pendingCount} unsynced records will be DELETED. Sync before signing out.'
                      : 'Local data is cleared on sign-out.'),
                  actions: [
                    TextButton(
                        onPressed: () => Navigator.pop(context, false),
                        child: const Text('Cancel')),
                    FilledButton(
                        onPressed: () => Navigator.pop(context, true),
                        child: const Text('Sign out')),
                  ],
                ),
              );
              if (yes == true) await app.logout();
            },
          ),
        ],
      ),
      body: tabs[tab].body,
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: [
          for (final t in tabs)
            NavigationDestination(icon: Icon(t.icon), label: t.label),
        ],
      ),
    );
  }

  static String _roleLabel(String role) => switch (role) {
        'super_admin' => 'Super Admin',
        'company_admin' => 'Company Admin',
        'company_gate' => 'Gate',
        'vendor_admin' => 'Vendor',
        'vendor_operator' => 'Vendor Operator',
        _ => role,
      };
}

/// Recent local + synced attendance activity.
class _ActivityScreen extends StatelessWidget {
  const _ActivityScreen();

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return FutureBuilder(
      future: app.recentAttendance(),
      builder: (context, snap) {
        final rows = snap.data ?? const [];
        if (snap.connectionState == ConnectionState.done && rows.isEmpty) {
          return const Center(child: Text('No attendance recorded yet.'));
        }
        return ListView.separated(
          itemCount: rows.length,
          separatorBuilder: (_, __) => const Divider(height: 1),
          itemBuilder: (context, i) {
            final m = rows[i];
            final isIn = m['type'] == 'IN';
            return ListTile(
              dense: true,
              leading: Icon(isIn ? Icons.login : Icons.logout,
                  color: isIn ? Colors.teal : Colors.orange),
              title: Text('${m['worker_name'] ?? 'Worker #${m['worker_server_id']}'}'
                  '  ·  ${m['type']}'),
              subtitle: Text(
                  '${ExceptionsScreen.fmt(m['marked_at'])}'
                  '${m['location_name'] != null ? ' · ${m['location_name']}' : ''}'
                  '${m['simulated'] == 1 ? ' · simulated' : ''}'),
              trailing: switch (m['sync_state'] as String? ?? 'synced') {
                'pending' => const Icon(Icons.cloud_upload,
                    size: 18, color: Colors.amber),
                'error' =>
                    const Icon(Icons.error, size: 18, color: Colors.red),
                _ => const Icon(Icons.cloud_done,
                    size: 18, color: Colors.teal),
              },
            );
          },
        );
      },
    );
  }
}
