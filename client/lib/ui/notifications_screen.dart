import 'package:flutter/material.dart';

import '../core/scope.dart';

/// In-app notification center — the same feed the web bell shows: approvals,
/// rejections, benched-worker and expiring-deployment digests. Online-only
/// (the feed lives on the server); pull-to-refresh.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load(markRead: true);
  }

  Future<void> _load({bool markRead = false}) async {
    final app = AppScope.of(context);
    if (!app.online) {
      setState(() { _loading = false; _error = 'Notifications need internet.'; });
      return;
    }
    try {
      final (rows, _) = await app.notifications();
      if (markRead) {
        // Opening the screen counts as reading.
        // ignore: unawaited_futures
        app.markNotificationsRead();
      }
      if (mounted) setState(() { _rows = rows; _loading = false; _error = null; });
    } catch (_) {
      if (mounted) setState(() { _loading = false; _error = 'Could not load notifications.'; });
    }
  }

  String _fmt(String? iso) {
    final d = DateTime.tryParse(iso ?? '');
    if (d == null) return '';
    final l = d.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(l.day)}/${two(l.month)} ${two(l.hour)}:${two(l.minute)}';
  }

  IconData _icon(String type) => switch (type) {
        'deployment_requested' => Icons.pending_actions,
        'deployment_decided' => Icons.assignment_turned_in,
        'deployment_expiring' => Icons.timer,
        'workers_undeployed' => Icons.person_search,
        'vendor_approved' => Icons.verified,
        'vendor_rejected' => Icons.block,
        'missing_out' => Icons.logout,
        _ => Icons.notifications,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: RefreshIndicator(
        onRefresh: () => _load(),
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? ListView(children: [
                    Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(_error!, textAlign: TextAlign.center))
                  ])
                : _rows.isEmpty
                    ? ListView(children: const [
                        Padding(
                            padding: EdgeInsets.all(32),
                            child: Text(
                                'Nothing yet — approvals, expiring deployments and alerts appear here.',
                                textAlign: TextAlign.center))
                      ])
                    : ListView.separated(
                        itemCount: _rows.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, i) {
                          final n = _rows[i];
                          final unread = n['read_at'] == null;
                          return ListTile(
                            leading: Icon(_icon('${n['type']}'),
                                color: unread
                                    ? const Color(0xFF10685A)
                                    : Colors.grey),
                            title: Text('${n['title']}',
                                style: TextStyle(
                                    fontWeight: unread
                                        ? FontWeight.w700
                                        : FontWeight.w400)),
                            subtitle: Text(
                                [
                                  if ((n['body'] ?? '').toString().isNotEmpty)
                                    '${n['body']}',
                                  _fmt('${n['created_at']}'),
                                ].join('\n'),
                                maxLines: 4,
                                overflow: TextOverflow.ellipsis),
                            isThreeLine:
                                (n['body'] ?? '').toString().isNotEmpty,
                          );
                        },
                      ),
      ),
    );
  }
}
