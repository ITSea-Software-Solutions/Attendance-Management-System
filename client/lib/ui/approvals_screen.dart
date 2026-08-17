import 'package:flutter/material.dart';

import '../core/scope.dart';

/// Company HR / admin — approve vendor deployments from the app (same powers
/// as the portal's approvals panel): multi-select workers, choose allowed
/// gates/departments (Main Gate preselected), approve in bulk or reject with
/// a reason. Online-only; the decision takes effect at every gate on next sync.
class ApprovalsScreen extends StatefulWidget {
  const ApprovalsScreen({super.key});
  @override
  State<ApprovalsScreen> createState() => _ApprovalsScreenState();
}

class _ApprovalsScreenState extends State<ApprovalsScreen> {
  List<Map<String, dynamic>> _rows = const [];
  List<String> _locations = const [];
  final Set<int> _selIds = {};
  final Set<String> _selLocs = {'Main Gate'};
  bool _allGates = false;
  bool _loading = true;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final app = AppScope.of(context);
    if (!app.online) {
      setState(() {
        _loading = false;
        _error = 'Approvals need internet.';
      });
      return;
    }
    try {
      final rows = await app.pendingAssignments();
      final locs = await app.companyLocations();
      if (!mounted) return;
      setState(() {
        _rows = rows;
        _locations = locs;
        _selIds.removeWhere((id) => !rows.any((r) => r['id'] == id));
        _loading = false;
        _error = null;
      });
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'Could not load pending approvals.';
        });
      }
    }
  }

  Future<void> _approve() async {
    final app = AppScope.of(context);
    setState(() => _busy = true);
    String msg;
    try {
      msg = await app.approveAssignments(_selIds.toList(),
          locations: _allGates ? null : _selLocs.toList());
      _selIds.clear();
    } catch (e) {
      msg = AppState.apiMessage(e, 'Approve failed — retry.');
    }
    if (!mounted) return;
    setState(() => _busy = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    await _load();
  }

  Future<void> _reject(Map<String, dynamic> a) async {
    final app = AppScope.of(context);
    final ctrl = TextEditingController();
    final go = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Reject ${a['worker']?['name'] ?? 'deployment'}?'),
        content: TextField(
          controller: ctrl,
          decoration: const InputDecoration(
              labelText: 'Reason (the vendor sees this)'),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel')),
          FilledButton(
              style: FilledButton.styleFrom(backgroundColor: Colors.red),
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Reject')),
        ],
      ),
    );
    if (go != true) return;
    String msg;
    try {
      msg = await app.rejectAssignment(
          a['id'] as int, ctrl.text.trim().isEmpty ? 'Rejected' : ctrl.text.trim());
    } catch (e) {
      msg = AppState.apiMessage(e, 'Reject failed — retry.');
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? ListView(children: [
                    Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(_error!, textAlign: TextAlign.center)),
                  ])
                : _rows.isEmpty
                    ? ListView(children: const [
                        Padding(
                          padding: EdgeInsets.all(32),
                          child: Text(
                              'No deployments waiting for approval.\nNew vendor deployments appear here first.',
                              textAlign: TextAlign.center),
                        ),
                      ])
                    : ListView(
                        padding: const EdgeInsets.only(bottom: 120),
                        children: [
                          for (final a in _rows)
                            CheckboxListTile(
                              value: _selIds.contains(a['id']),
                              onChanged: (v) => setState(() => v == true
                                  ? _selIds.add(a['id'] as int)
                                  : _selIds.remove(a['id'])),
                              title: Text(
                                  '${a['worker']?['name'] ?? 'Worker #${a['worker_id']}'}'),
                              subtitle: Text(
                                '${a['vendor']?['name'] ?? ''} · ${a['start_date']} → ${a['end_date']}'
                                '${a['created_at'] != null ? '\nrequested ${'${a['created_at']}'.substring(0, 10)}' : ''}',
                                style: const TextStyle(fontSize: 12),
                              ),
                              isThreeLine: a['created_at'] != null,
                              secondary: IconButton(
                                tooltip: 'Reject',
                                icon:
                                    const Icon(Icons.block, color: Colors.red),
                                onPressed: _busy ? null : () => _reject(a),
                              ),
                            ),
                        ],
                      ),
      ),
      bottomNavigationBar: (_rows.isEmpty || _error != null)
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  // Gates the approved workers may use.
                  SizedBox(
                    height: 40,
                    child: ListView(
                      scrollDirection: Axis.horizontal,
                      children: [
                        FilterChip(
                          label: const Text('All gates'),
                          selected: _allGates,
                          onSelected: (v) => setState(() => _allGates = v),
                        ),
                        const SizedBox(width: 6),
                        if (!_allGates)
                          for (final l in _locations)
                            Padding(
                              padding: const EdgeInsets.only(right: 6),
                              child: FilterChip(
                                label: Text(l),
                                selected: _selLocs.contains(l),
                                onSelected: (v) => setState(() =>
                                    v ? _selLocs.add(l) : _selLocs.remove(l)),
                              ),
                            ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 6),
                  FilledButton.icon(
                    onPressed: _selIds.isEmpty ||
                            _busy ||
                            (!_allGates && _selLocs.isEmpty)
                        ? null
                        : _approve,
                    icon: const Icon(Icons.check_circle),
                    label: Text(_selIds.isEmpty
                        ? 'Select workers to approve'
                        : 'Approve ${_selIds.length} for ${_allGates ? 'all gates' : _selLocs.join(', ')}'),
                  ),
                ]),
              ),
            ),
    );
  }
}
