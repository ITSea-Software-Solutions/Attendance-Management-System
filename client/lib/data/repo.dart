import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart' show FormData, MultipartFile;
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import 'db.dart';

const _uuid = Uuid();

/// Session + sync + local repositories, exposed app-wide via [AppState].
class AppState extends ChangeNotifier {
  Map<String, dynamic>? user; // decoded user json (role, ids, location)
  bool online = false;
  bool syncing = false;
  String? lastSyncAt;
  int pendingCount = 0;

  String get role => (user?['role'] ?? '') as String;
  bool get isVendor => role == 'vendor_admin' || role == 'vendor_operator';
  bool get isGate => role == 'company_gate';
  bool get isCompanyAdmin => role == 'company_admin';
  bool get isSuperAdmin => role == 'super_admin';
  bool get canMark => isGate || isCompanyAdmin || isSuperAdmin;

  Future<void> bootstrap() async {
    final u = await LocalDb.getMeta('user');
    if (u != null) user = jsonDecode(u) as Map<String, dynamic>;
    lastSyncAt = await LocalDb.getMeta('last_sync');
    await _refreshPending();
    Connectivity().onConnectivityChanged.listen((results) {
      final was = online;
      online = !results.contains(ConnectivityResult.none);
      if (online && !was) sync(); // regain → push/pull
      notifyListeners();
    });
    final now = await Connectivity().checkConnectivity();
    online = !now.contains(ConnectivityResult.none);
    notifyListeners();
    if (user != null && online) await sync();
  }

  Future<void> login(String server, String email, String password) async {
    final data = await Api.login(server, email, password);
    await _adoptSession(server, data);
  }

  /// SaaS self-service signup (company or vendor) — same as the web portal:
  /// starts on Trial; choosing a paid plan files an offline-payment upgrade
  /// request. Auto-logs-in with the returned token.
  Future<String> signup(String server, Map<String, dynamic> payload) async {
    final data = await Api.signup(server, payload);
    await _adoptSession(server, data);
    return (data['message'] ?? 'Account created — welcome to AMS!') as String;
  }

  Future<void> _adoptSession(String server, Map<String, dynamic> data) async {
    await LocalDb.setMeta('server', server);
    await LocalDb.setMeta('token', data['token'] as String);
    await LocalDb.setMeta('user', jsonEncode(data['user']));
    user = Map<String, dynamic>.from(data['user'] as Map);
    Api.reset();
    notifyListeners();
    await sync();
  }

  Future<void> logout() async {
    await LocalDb.wipeSession();
    Api.reset();
    user = null;
    notifyListeners();
  }

  // ── Sync ───────────────────────────────────────────────────────────────────

  Future<String?> sync() async {
    if (user == null || syncing) return null;
    syncing = true;
    notifyListeners();
    try {
      await _push();
      await _pull();
      lastSyncAt = DateTime.now().toIso8601String();
      await LocalDb.setMeta('last_sync', lastSyncAt!);
      online = true;
      return null;
    } catch (e) {
      online = false;
      return e.toString();
    } finally {
      await _refreshPending();
      syncing = false;
      notifyListeners();
    }
  }

  /// Push locally-created registrations + attendance (idempotent by uuid).
  Future<void> _push() async {
    final db = await LocalDb.instance();
    final regs = await db.query('workers',
        where: "sync_state IN ('pending','error')");
    final marks = await db.query('attendance',
        where: "sync_state IN ('pending','error')");
    if (regs.isEmpty && marks.isEmpty) return;

    final api = await Api.client();
    final r = await api.post('/sync/push', data: {
      'device_id': await _deviceId(),
      'registrations': regs
          .map((w) => {
                'uuid': w['client_uuid'],
                'name': w['name'],
                'aadhaar_number': w['aadhaar_number'],
                'dob': w['dob'],
                'gender': w['gender'],
                'phone': w['phone'],
                // consent is gated by a mandatory checkbox in the register
                // flow — a row can only exist if it was confirmed.
                'consent': true,
                'fingerprint_template': w['fingerprint_template'],
                'fingerprint_quality': w['fingerprint_quality'],
              })
          .toList(),
      'marks': marks
          .map((m) => {
                'uuid': m['client_uuid'],
                'worker_id': m['worker_server_id'],
                'worker_uuid': m['worker_uuid'],
                'type': m['type'],
                'marked_at': m['marked_at'],
                'method': m['method'],
                'score': m['score'],
                'simulated': m['simulated'] == 1,
              })
          .toList(),
    });
    final res = Map<String, dynamic>.from(r.data as Map);

    for (final item in List<Map>.from(res['registrations'] as List? ?? [])) {
      final ok = item['status'] == 'created' || item['status'] == 'duplicate_uuid';
      await db.update(
          'workers',
          ok
              ? {
                  'server_id': item['server_id'],
                  'aadhaar_masked': item['aadhaar_number_masked'],
                  'aadhaar_number': null, // discard raw number once server has it
                  'fingerprint_template': null, // server holds it encrypted now
                  'sync_state': 'synced',
                  'sync_error': null,
                }
              : {'sync_state': 'error', 'sync_error': item['message']},
          where: 'client_uuid = ?',
          whereArgs: [item['uuid']]);
    }

    // Photos: once a worker has a server id, upload the registration photo —
    // the server stores it privately AND auto-enrolls the face for camera
    // attendance. Best-effort; retried on every sync until it succeeds.
    final photoRows = await db.query('workers',
        where: 'photo_path IS NOT NULL AND photo_synced = 0 AND server_id IS NOT NULL');
    for (final w in photoRows) {
      try {
        final fd = FormData.fromMap({
          'photo': await MultipartFile.fromFile(w['photo_path'] as String,
              filename: 'worker.jpg'),
        });
        await api.post('/workers/${w['server_id']}/photo', data: fd);
        await db.update('workers', {'photo_synced': 1},
            where: 'client_uuid = ?', whereArgs: [w['client_uuid']]);
      } catch (_) {/* retry next sync */}
    }
    // Aadhaar PDFs: same post-sync pattern as photos — once the server id
    // exists, attach the PDF the vendor imported during registration.
    final pdfRows = await db.query('workers',
        where: 'aadhaar_pdf_path IS NOT NULL AND aadhaar_pdf_synced = 0 AND server_id IS NOT NULL');
    for (final w in pdfRows) {
      try {
        final fd = FormData.fromMap({
          'aadhaar_number_masked': w['aadhaar_masked'],
          'pdf': await MultipartFile.fromFile(w['aadhaar_pdf_path'] as String,
              filename: 'aadhaar.pdf'),
        });
        await api.post('/aadhaar/upload/${w['server_id']}', data: fd);
        await db.update('workers', {'aadhaar_pdf_synced': 1},
            where: 'client_uuid = ?', whereArgs: [w['client_uuid']]);
      } catch (_) {/* retry next sync */}
    }
    // Aadhaar DOCUMENT photo (extracted from the PDF at registration):
    // uploaded once the worker has a server id — gate screens show it
    // beside the live photos.
    final aPhotoRows = await db.query('workers',
        where: 'aadhaar_photo_b64 IS NOT NULL AND aadhaar_photo_synced = 0 AND server_id IS NOT NULL');
    for (final w in aPhotoRows) {
      try {
        final tmp = File('${Directory.systemTemp.path}/aadhaar_photo_${w['server_id']}.png');
        await tmp.writeAsBytes(base64Decode(w['aadhaar_photo_b64'] as String));
        final fd = FormData.fromMap({
          'photo': await MultipartFile.fromFile(tmp.path, filename: 'aadhaar.png'),
        });
        await api.post('/workers/${w['server_id']}/aadhaar-photo', data: fd);
        await db.update('workers', {'aadhaar_photo_synced': 1},
            where: 'client_uuid = ?', whereArgs: [w['client_uuid']]);
        try { await tmp.delete(); } catch (_) {}
      } catch (_) {/* retry next sync */}
    }
    for (final item in List<Map>.from(res['marks'] as List? ?? [])) {
      final ok = item['status'] == 'created' || item['status'] == 'duplicate_uuid';
      await db.update(
          'attendance',
          ok
              ? {
                  'server_id': item['server_id'],
                  'sync_state': 'synced',
                  'sync_error': null
                }
              : {'sync_state': 'error', 'sync_error': item['message']},
          where: 'client_uuid = ?',
          whereArgs: [item['uuid']]);
    }

    // Gate proof photos: attach to their synced marks (best-effort, retried).
    final proofRows = await db.query('attendance',
        where: 'proof_path IS NOT NULL AND proof_synced = 0 AND server_id IS NOT NULL');
    for (final m in proofRows) {
      try {
        final fd = FormData.fromMap({
          'photo': await MultipartFile.fromFile(m['proof_path'] as String,
              filename: 'proof.jpg'),
        });
        await api.post('/attendance/${m['server_id']}/proof', data: fd);
        await db.update('attendance', {'proof_synced': 1},
            where: 'client_uuid = ?', whereArgs: [m['client_uuid']]);
      } catch (_) {/* retry next sync */}
    }
  }

  /// Pull the role-scoped bundle; server data wins for master records.
  Future<void> _pull() async {
    final api = await Api.client();
    final r = await api.get('/sync/pull');
    final data = Map<String, dynamic>.from(r.data as Map);
    final db = await LocalDb.instance();

    final workers = List<Map>.from(data['workers'] as List? ?? []);
    for (final w in workers) {
      final uuid = (w['client_uuid'] as String?) ?? 'srv-${w['id']}';
      final existing = await db.query('workers',
          where: 'client_uuid = ? OR server_id = ?',
          whereArgs: [uuid, w['id']]);
      final row = {
        'client_uuid': existing.isEmpty
            ? uuid
            : existing.first['client_uuid'] as String,
        'server_id': w['id'],
        'name': w['name'],
        'aadhaar_masked': w['aadhaar_number_masked'],
        'aadhaar_number': null,
        'dob': w['dob']?.toString(),
        'gender': w['gender'],
        'phone': w['phone'],
        'status': w['status'],
        'vendor_id': w['vendor_id'],
        // Enrolled template (marking-capable roles only — server decides):
        // enables OFFLINE 1:N fingerprint matching at this gate device.
        'fingerprint_template': w['fingerprint_template'],
        'sync_state': 'synced',
        'sync_error': null,
        'updated_at': w['updated_at']?.toString(),
      };
      if (existing.isEmpty) {
        await db.insert('workers', row);
      } else if (existing.first['sync_state'] == 'synced') {
        // server wins for already-synced master data
        await db.update('workers', row,
            where: 'client_uuid = ?', whereArgs: [row['client_uuid']]);
      }
    }

    await db.delete('assignments');
    for (final a in List<Map>.from(data['assignments'] as List? ?? [])) {
      await db.insert('assignments', {
        'server_id': a['id'],
        'worker_server_id': a['worker_id'],
        'company_id': a['company_id'],
        'company_name': a['company_name'],
        'start_date': a['start_date']?.toString(),
        'end_date': a['end_date']?.toString(),
        'status': a['status'],
      });
    }

    // Recent attendance (server copy) — refresh synced rows only.
    for (final m in List<Map>.from(data['attendance'] as List? ?? [])) {
      final uuid = (m['client_uuid'] as String?) ?? 'srv-${m['id']}';
      final existing = await db
          .query('attendance', where: 'client_uuid = ?', whereArgs: [uuid]);
      final row = {
        'client_uuid': uuid,
        'server_id': m['id'],
        'worker_server_id': m['worker_id'],
        'worker_name': m['worker_name'],
        'company_id': m['company_id'],
        'type': m['type'],
        'marked_at': m['marked_at']?.toString(),
        'method': m['method'] ?? 'fingerprint',
        'score': m['fingerprint_score'],
        'simulated': 0,
        'location_type': m['location_type'],
        'location_name': m['location_name'],
        'sync_state': 'synced',
      };
      if (existing.isEmpty) {
        await db.insert('attendance', row);
      }
    }
  }

  /// Face attendance (online): identify a worker from a photo (server-side
  /// ArcFace 1:N), returning the same shape the web gate uses.
  Future<Map<String, dynamic>> identifyFace(String photoPath) async {
    final api = await Api.client();
    final fd = FormData.fromMap({
      'company_id': user?['company_id'],
      'photo': await MultipartFile.fromFile(photoPath, filename: 'face.jpg'),
    });
    final r = await api.post('/attendance/identify-face', data: fd);
    return Map<String, dynamic>.from(r.data as Map);
  }

  /// Face attendance mark (online): the same photo is proof AND the server's
  /// re-verification probe — the client can never assert a match.
  Future<String> markFace(Map<String, dynamic> w, String type, String photoPath) async {
    final api = await Api.client();
    final fd = FormData.fromMap({
      'worker_id': w['worker_id'],
      'company_id': user?['company_id'],
      if (w['assignment_id'] != null) 'assignment_id': w['assignment_id'],
      'type': type,
      'method': 'face',
      'location_type': user?['location_type'] ?? 'main_gate',
      'location_name': user?['location_name'] ?? 'Main Gate',
      'photo': await MultipartFile.fromFile(photoPath, filename: 'face.jpg'),
    });
    final r = await api.post('/attendance/mark', data: fd);
    await sync(); // pull the fresh mark into the local activity view
    return (r.data is Map ? (r.data['message'] ?? 'Marked.') : 'Marked.').toString();
  }

  /// Aadhaar PDF import (online): server-side extraction, no storage.
  /// Returns the extracted data map (name/dob/gender/aadhaar fields).
  Future<Map<String, dynamic>> extractAadhaar(String pdfPath,
      {String? password}) async {
    final api = await Api.client();
    final fd = FormData.fromMap({
      if (password != null && password.isNotEmpty) 'password': password,
      'pdf': await MultipartFile.fromFile(pdfPath, filename: 'aadhaar.pdf'),
    });
    final r = await api.post('/aadhaar/extract', data: fd);
    final body = Map<String, dynamic>.from(r.data as Map);
    return Map<String, dynamic>.from(body['data'] as Map? ?? {});
  }

  /// Enrollment identity check: Aadhaar PDF photo vs live camera photo,
  /// compared server-side (ArcFace). Advisory — old Aadhaar photos score low.
  Future<Map<String, dynamic>> verifyAadhaarFace(
      String aadhaarPhotoB64, String livePhotoPath) async {
    final api = await Api.client();
    final fd = FormData.fromMap({
      'aadhaar_photo_base64': aadhaarPhotoB64,
      'live_photo':
          await MultipartFile.fromFile(livePhotoPath, filename: 'live.jpg'),
    });
    final r = await api.post('/aadhaar/face-verify', data: fd);
    return Map<String, dynamic>.from(r.data as Map);
  }

  /// Authenticated URL + headers for the Aadhaar DOCUMENT photo.
  Future<(String, Map<String, String>)?> aadhaarPhotoRequest(int? serverId) async {
    if (serverId == null) return null;
    final server = await LocalDb.getMeta('server');
    final token = await LocalDb.getMeta('token');
    if (server == null || token == null) return null;
    return (
      '$server/api/workers/$serverId/aadhaar-photo',
      {'Authorization': 'Bearer $token', 'Accept': 'image/*'}
    );
  }

  /// Authenticated URL + headers for a worker's photo (gate result card).
  Future<(String, Map<String, String>)?> workerPhotoRequest(
      int? serverId) async {
    if (serverId == null) return null;
    final server = await LocalDb.getMeta('server');
    final token = await LocalDb.getMeta('token');
    if (server == null || token == null) return null;
    return (
      '$server/api/workers/$serverId/photo',
      {'Authorization': 'Bearer $token', 'Accept': 'image/*'}
    );
  }

  // ── Vendor: online admin actions (deploy, stats, companies) ───────────────

  /// Companies this vendor can deploy to (approved access only).
  Future<List<Map<String, dynamic>>> approvedCompanies() async {
    final api = await Api.client();
    final r = await api.get('/vendors/${user?['vendor_id']}/available-companies');
    final rows = List<Map>.from(
        (r.data is Map ? r.data['companies'] ?? r.data['data'] ?? [] : r.data) as List);
    return rows
        .map(Map<String, dynamic>.from)
        .where((c) => (c['status'] ?? c['pivot_status'] ?? '') == 'approved')
        .toList();
  }

  /// Deploy a worker to a company for a date range (online).
  Future<String> deployWorker({
    required int workerServerId,
    required int companyId,
    required String startDate,
    required String endDate,
  }) async {
    final api = await Api.client();
    final r = await api.post('/assignments', data: {
      'worker_id': workerServerId,
      'company_id': companyId,
      'start_date': startDate,
      'end_date': endDate,
    });
    await sync(); // refresh local assignments
    return (r.data is Map ? (r.data['message'] ?? 'Deployed.') : 'Deployed.')
        .toString();
  }

  /// Worker analytics from the server (days present, hours, per-month rows).
  Future<Map<String, dynamic>> workerStats(int serverId) async {
    final api = await Api.client();
    final r = await api.get('/workers/$serverId/stats');
    return Map<String, dynamic>.from(r.data as Map);
  }

  /// Diagnostics: timed round-trip to the server (auth'd, tiny endpoint).
  Future<Map<String, Object?>> serverProbe() async {
    final server = await LocalDb.getMeta('server') ?? '(not set)';
    final sw = Stopwatch()..start();
    try {
      final api = await Api.client();
      await api.get('/plan');
      sw.stop();
      return {'ok': true, 'ms': sw.elapsedMilliseconds, 'server': server};
    } catch (e) {
      sw.stop();
      final t = e.toString();
      String why = 'Unreachable — check internet / server address.';
      if (t.contains('401')) why = 'Reachable, but the session expired — sign in again.';
      return {'ok': false, 'ms': sw.elapsedMilliseconds, 'server': server, 'error': why};
    }
  }

  Future<String> _deviceId() async {
    var id = await LocalDb.getMeta('device_id');
    if (id == null) {
      id = _uuid.v4();
      await LocalDb.setMeta('device_id', id);
    }
    return id;
  }

  Future<void> _refreshPending() async {
    final db = await LocalDb.instance();
    final w = await db.rawQuery(
        "SELECT COUNT(*) c FROM workers WHERE sync_state != 'synced'");
    final a = await db.rawQuery(
        "SELECT COUNT(*) c FROM attendance WHERE sync_state != 'synced'");
    pendingCount = (w.first['c'] as int) + (a.first['c'] as int);
  }

  // ── Vendor: local registration (works offline) ────────────────────────────

  Future<String?> registerWorker({
    required String name,
    required String aadhaar,
    String? dob,
    String? gender,
    String? phone,
    String? fingerprintTemplate,
    int? fingerprintQuality,
    bool fingerprintSimulated = false,
    String? photoPath,
    String? aadhaarPdfPath,
    String? aadhaarPhotoB64,
  }) async {
    if (!RegExp(r'^\d{12}$').hasMatch(aadhaar)) {
      return 'Aadhaar must be exactly 12 digits.';
    }
    final db = await LocalDb.instance();
    final dupe = await db.query('workers',
        where: 'aadhaar_masked = ? OR aadhaar_number = ?',
        whereArgs: ['XXXX-XXXX-${aadhaar.substring(8)}', aadhaar]);
    if (dupe.isNotEmpty) {
      return 'A worker with this Aadhaar already exists on this device.';
    }
    await db.insert('workers', {
      'client_uuid': _uuid.v4(),
      'name': name,
      'aadhaar_number': aadhaar,
      'aadhaar_masked': 'XXXX-XXXX-${aadhaar.substring(8)}',
      'dob': dob,
      'gender': gender,
      'phone': phone,
      'fingerprint_template': fingerprintTemplate,
      'fingerprint_quality': fingerprintQuality,
      'fp_simulated': fingerprintSimulated ? 1 : 0,
      'photo_path': photoPath,
      'photo_synced': 0,
      'aadhaar_pdf_path': aadhaarPdfPath,
      'aadhaar_pdf_synced': 0,
      'aadhaar_photo_b64': aadhaarPhotoB64,
      'aadhaar_photo_synced': 0,
      'status': fingerprintTemplate != null ? 'active' : 'pending',
      'sync_state': 'pending',
    });
    await _refreshPending();
    notifyListeners();
    if (online) sync();
    return null;
  }

  // ── Gate: attendance ───────────────────────────────────────────────────────

  /// Workers deployed to this gate's company today (for identify + display).
  Future<List<Map<String, Object?>>> deployedWorkers() async {
    final db = await LocalDb.instance();
    final today = DateTime.now().toIso8601String().substring(0, 10);
    return db.rawQuery('''
      SELECT DISTINCT w.* FROM workers w
      JOIN assignments a ON a.worker_server_id = w.server_id
      WHERE a.status = 'active' AND a.start_date <= ? AND a.end_date >= ?
        AND w.status = 'active'
      ORDER BY w.name
    ''', [today, today]);
  }

  /// Last IN/OUT for a worker → suggests the next type.
  Future<String> nextTypeFor(int workerServerId) async {
    final db = await LocalDb.instance();
    final r = await db.query('attendance',
        where: 'worker_server_id = ?',
        whereArgs: [workerServerId],
        orderBy: 'marked_at DESC',
        limit: 1);
    if (r.isEmpty) return 'IN';
    return (r.first['type'] == 'IN') ? 'OUT' : 'IN';
  }

  Future<void> markAttendance({
    required Map<String, Object?> worker,
    required String type,
    required String method,
    int? score,
    bool simulated = false,
    String? proofPath,
  }) async {
    final db = await LocalDb.instance();
    await db.insert('attendance', {
      'client_uuid': _uuid.v4(),
      'worker_uuid': worker['client_uuid'],
      'worker_server_id': worker['server_id'],
      'worker_name': worker['name'],
      'company_id': user?['company_id'],
      'type': type,
      'marked_at': DateTime.now().toIso8601String(),
      'method': method,
      'score': score,
      'simulated': simulated ? 1 : 0,
      'location_type': user?['location_type'],
      'location_name': user?['location_name'],
      'proof_path': proofPath,
      'proof_synced': 0,
      'sync_state': 'pending',
    });
    await _refreshPending();
    notifyListeners();
    if (online) sync();
  }

  /// Workers currently inside (last event is IN, no OUT after).
  Future<List<Map<String, Object?>>> currentlyInside() async {
    final db = await LocalDb.instance();
    return db.rawQuery('''
      SELECT worker_name, worker_server_id, MAX(marked_at) last_at,
             (SELECT type FROM attendance a2
               WHERE a2.worker_server_id = a1.worker_server_id
               ORDER BY marked_at DESC LIMIT 1) last_type
      FROM attendance a1
      GROUP BY worker_server_id, worker_name
      HAVING last_type = 'IN'
      ORDER BY last_at DESC
    ''');
  }

  Future<List<Map<String, Object?>>> recentAttendance({int limit = 50}) async {
    final db = await LocalDb.instance();
    return db.query('attendance', orderBy: 'marked_at DESC', limit: limit);
  }

  Future<List<Map<String, Object?>>> workers({String? search}) async {
    final db = await LocalDb.instance();
    if (search == null || search.isEmpty) {
      return db.query('workers', orderBy: 'name');
    }
    return db.query('workers',
        where: 'name LIKE ?', whereArgs: ['%$search%'], orderBy: 'name');
  }
}
