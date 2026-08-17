import 'dart:io';

import 'package:flutter/material.dart';

import '../core/scope.dart';

/// Detail sheet for one attendance event: all three photos (Aadhaar document,
/// registration, the gate capture of THIS mark) plus the full story — type,
/// time, gate, method, score, sync state. Photos need internet except a gate
/// capture still on this device.
Future<void> showAttendanceDetail(
    BuildContext context, Map<String, Object?> m) async {
  final app = AppScope.of(context);
  final workerServerId = m['worker_server_id'] as int?;
  final photoReq =
      app.online ? await app.workerPhotoRequest(workerServerId) : null;
  final aadhaarReq =
      app.online ? await app.aadhaarPhotoRequest(workerServerId) : null;
  // Gate capture: local file if still on this device, else the synced copy.
  final localProof = (m['proof_path'] as String?);
  final proofReq = (localProof == null && app.online)
      ? await app.proofPhotoRequest(m['server_id'] as int?)
      : null;
  if (!context.mounted) return;

  Widget photo(String label, Widget? img) => Expanded(
        child: Column(children: [
          AspectRatio(
            aspectRatio: 3 / 4,
            child: ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: img ??
                  Container(
                    color: Colors.grey.shade200,
                    child: const Icon(Icons.person_off_outlined,
                        color: Colors.grey),
                  ),
            ),
          ),
          const SizedBox(height: 4),
          Text(label,
              style: const TextStyle(fontSize: 11, color: Colors.grey)),
        ]),
      );

  Widget net((String, Map<String, String>)? req) => req == null
      ? Container(
          color: Colors.grey.shade200,
          child: const Icon(Icons.cloud_off, color: Colors.grey))
      : Image.network(req.$1,
          headers: req.$2,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => Container(
              color: Colors.grey.shade200,
              child: const Icon(Icons.person_off_outlined,
                  color: Colors.grey)));

  final isIn = m['type'] == 'IN';
  String fmt(Object? iso) {
    final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (d == null) return '?';
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(d.day)}/${two(d.month)}/${d.year} ${two(d.hour)}:${two(d.minute)}';
  }

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
            Icon(isIn ? Icons.login : Icons.logout,
                color: isIn ? Colors.teal : Colors.orange),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                  '${m['worker_name'] ?? 'Worker #$workerServerId'} · ${m['type']}',
                  style: Theme.of(context).textTheme.titleMedium),
            ),
          ]),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              photo('Aadhaar', net(aadhaarReq)),
              const SizedBox(width: 10),
              photo('Registration', net(photoReq)),
              const SizedBox(width: 10),
              photo(
                  'Gate capture',
                  localProof != null
                      ? Image.file(File(localProof),
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => Container(
                              color: Colors.grey.shade200,
                              child: const Icon(Icons.no_photography_outlined,
                                  color: Colors.grey)))
                      : net(proofReq)),
            ],
          ),
          const SizedBox(height: 14),
          for (final row in [
            ('Time', fmt(m['marked_at'])),
            ('Gate', '${m['location_name'] ?? 'Main Gate'}'),
            (
              'Method',
              '${m['method'] ?? 'fingerprint'}'
                  '${m['score'] != null ? ' · score ${m['score']}' : ''}'
                  '${m['simulated'] == 1 ? ' · simulated' : ''}'
            ),
            (
              'Sync',
              switch (m['sync_state'] as String? ?? 'synced') {
                'pending' => 'waiting to upload',
                'error' => 'failed — ${m['sync_error'] ?? 'unknown'}',
                _ => 'synced to server',
              }
            ),
          ])
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(children: [
                SizedBox(
                    width: 72,
                    child: Text(row.$1,
                        style: const TextStyle(
                            fontSize: 12, color: Colors.grey))),
                Expanded(
                    child:
                        Text(row.$2, style: const TextStyle(fontSize: 13))),
              ]),
            ),
        ],
      ),
    ),
  );
}
