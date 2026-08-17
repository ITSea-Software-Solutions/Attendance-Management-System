import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Local offline store (SQLite via FFI on every platform).
///
/// Tables:
///  - meta               key/value: session token, user json, server url, cursors
///  - workers            cached + locally-created workers (client_uuid identity)
///  - assignments        deployments relevant to this device's role
///  - attendance         local attendance events (queued until synced)
///  - outbox_workers     registration queue -> POST /api/sync/push
class LocalDb {
  static Database? _db;

  static Future<Database> instance() async {
    if (_db != null) return _db!;
    sqfliteFfiInit();
    final factory = databaseFactoryFfi;
    final dir = await getApplicationSupportDirectory();
    await Directory(dir.path).create(recursive: true);
    final path = p.join(dir.path, 'ams_client.db');
    _db = await factory.openDatabase(path,
        options: OpenDatabaseOptions(
          version: 6,
          onUpgrade: (db, from, to) async {
            if (from < 2) {
              for (final c in [
                "ALTER TABLE workers ADD COLUMN fingerprint_template TEXT",
                "ALTER TABLE workers ADD COLUMN fingerprint_quality INTEGER",
                "ALTER TABLE workers ADD COLUMN fp_simulated INTEGER DEFAULT 0",
                "ALTER TABLE workers ADD COLUMN photo_path TEXT",
                "ALTER TABLE workers ADD COLUMN photo_synced INTEGER DEFAULT 0",
              ]) {
                try { await db.execute(c); } catch (_) {}
              }
            }
            if (from < 3) {
              for (final c in [
                "ALTER TABLE workers ADD COLUMN aadhaar_pdf_path TEXT",
                "ALTER TABLE workers ADD COLUMN aadhaar_pdf_synced INTEGER DEFAULT 0",
              ]) {
                try { await db.execute(c); } catch (_) {}
              }
            }
            if (from < 6) {
              for (final c in [
                "ALTER TABLE assignments ADD COLUMN created_at TEXT",
                "ALTER TABLE assignments ADD COLUMN approved_at TEXT",
              ]) {
                try { await db.execute(c); } catch (_) {}
              }
            }
            if (from < 5) {
              for (final c in [
                "ALTER TABLE assignments ADD COLUMN approval_status TEXT DEFAULT 'approved'",
                "ALTER TABLE assignments ADD COLUMN allowed_locations TEXT",
              ]) {
                try { await db.execute(c); } catch (_) {}
              }
            }
            if (from < 4) {
              for (final c in [
                "ALTER TABLE workers ADD COLUMN aadhaar_photo_b64 TEXT",
                "ALTER TABLE workers ADD COLUMN aadhaar_photo_synced INTEGER DEFAULT 0",
                "ALTER TABLE attendance ADD COLUMN proof_path TEXT",
                "ALTER TABLE attendance ADD COLUMN proof_synced INTEGER DEFAULT 0",
              ]) {
                try { await db.execute(c); } catch (_) {}
              }
            }
          },
          onCreate: (db, v) async {
            await db.execute('''
              CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)
            ''');
            await db.execute('''
              CREATE TABLE workers (
                client_uuid TEXT PRIMARY KEY,
                server_id INTEGER,
                name TEXT NOT NULL,
                aadhaar_masked TEXT,
                aadhaar_number TEXT,          -- held ONLY until synced, then nulled
                dob TEXT, gender TEXT, phone TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                vendor_id INTEGER,
                photo_note TEXT,
                fingerprint_template TEXT,     -- held until synced (server encrypts at rest)
                fingerprint_quality INTEGER,
                fp_simulated INTEGER DEFAULT 0,
                photo_path TEXT,               -- local file; uploaded post-sync (face auto-enroll)
                photo_synced INTEGER DEFAULT 0,
                aadhaar_pdf_path TEXT,         -- local Aadhaar PDF; uploaded post-sync
                aadhaar_pdf_synced INTEGER DEFAULT 0,
                aadhaar_photo_b64 TEXT,        -- photo EXTRACTED from the PDF; uploaded post-sync
                aadhaar_photo_synced INTEGER DEFAULT 0,
                sync_state TEXT NOT NULL DEFAULT 'synced',  -- synced|pending|error
                sync_error TEXT,
                updated_at TEXT
              )
            ''');
            await db.execute('''
              CREATE TABLE assignments (
                server_id INTEGER PRIMARY KEY,
                worker_uuid TEXT, worker_server_id INTEGER,
                company_id INTEGER, company_name TEXT,
                start_date TEXT, end_date TEXT, status TEXT,
                approval_status TEXT DEFAULT 'approved',
                allowed_locations TEXT,          -- JSON list; NULL = all gates
                created_at TEXT, approved_at TEXT
              )
            ''');
            await db.execute('''
              CREATE TABLE attendance (
                client_uuid TEXT PRIMARY KEY,
                server_id INTEGER,
                worker_uuid TEXT, worker_server_id INTEGER, worker_name TEXT,
                company_id INTEGER,
                type TEXT NOT NULL,           -- IN | OUT
                marked_at TEXT NOT NULL,      -- ISO8601 device time
                method TEXT NOT NULL,         -- fingerprint | manual
                score INTEGER,
                simulated INTEGER NOT NULL DEFAULT 0,
                location_type TEXT, location_name TEXT,
                proof_path TEXT,              -- gate camera capture; uploaded post-sync
                proof_synced INTEGER DEFAULT 0,
                sync_state TEXT NOT NULL DEFAULT 'pending',
                sync_error TEXT
              )
            ''');
            await db.execute(
                'CREATE INDEX idx_att_worker ON attendance(worker_server_id, marked_at)');
          },
        ));
    return _db!;
  }

  // ── meta helpers ──────────────────────────────────────────────────────────
  static Future<String?> getMeta(String k) async {
    final db = await instance();
    final r = await db.query('meta', where: 'k = ?', whereArgs: [k]);
    return r.isEmpty ? null : r.first['v'] as String?;
  }

  static Future<void> setMeta(String k, String? v) async {
    final db = await instance();
    if (v == null) {
      await db.delete('meta', where: 'k = ?', whereArgs: [k]);
    } else {
      await db.insert('meta', {'k': k, 'v': v},
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  static Future<void> wipeSession() async {
    final db = await instance();
    await db.delete('meta');
    await db.delete('workers');
    await db.delete('assignments');
    await db.delete('attendance');
  }
}
