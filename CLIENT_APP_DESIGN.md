# AMS Client App — Design & Scope (Flutter, Windows + Android)

Status: DRAFT v1 — agreed scope as of 2026-08-15. This document is the source of
truth for the cross-platform client app. Read together with `HANDOFF.md` (session
context) and `CLAUDE.md` (existing web/server system).

---

## 1. Vision & Role Split

One server, three client surfaces, all in sync:

| Surface | Who | Where it runs | Connectivity |
|---|---|---|---|
| **Web portal (existing React)** | super_admin, company_admin (approvals, users, reports) | Browser → server | Online only |
| **Flutter app — Vendor mode** | vendor_admin, vendor_operator | Windows desktop + Android | **Offline-first**, syncs when online |
| **Flutter app — Gate mode (kiosk)** | company_gate | Windows desktop + Android tablet | **Offline-first**, syncs when online |

- Server (Laravel API) remains the **single source of truth**. Every device pushes
  its local changes up and pulls masters down whenever it has internet ("always
  online when possible").
- Each app instance keeps a **local encrypted copy** (SQLite) of the data relevant
  to it — this is both the offline working set and the local backup the user asked for.
- Super admin functions stay server/web-only. No admin features in the app.

## 2. Why Flutter/Dart (decision record)

Requirement: ONE codebase for Windows desktop + Android, talking to local camera
and SecuGen thumb scanner, with offline storage.

- **Flutter** is the strongest option for the Windows+Android pair. Considered and
  rejected: React Native (Windows support weak), Electron (no Android), .NET MAUI
  (new language for team, weaker plugin ecosystem for this).
- **SecuGen integration paths** (device: Hamster Pro 20 / HU20-AP already owned):
  - Windows: EITHER keep SGIBIOSRV local HTTP service (`https://localhost:8443`,
    zero native work, same as browser today) OR `dart:ffi` directly against the
    SecuGen DLLs (`sgfplib.dll` — already in repo under `biometric-agent/`).
    Start with SGIBIOSRV, move to FFI if the extra service becomes operational pain.
  - Android: SecuGen **FDx SDK Pro for Android** (Java AAR) over **USB-OTG**, wrapped
    in a Flutter platform channel (MethodChannel). USB permission flow handled natively.
- **CRITICAL WIN — on-device matching:** the SecuGen SDK includes a real template
  matcher on BOTH platforms (SGIMatchScore locally). The app matches locally →
  (a) real matching replaces the server's placeholder for the gate flow,
  (b) attendance keeps working with zero internet.
- Camera: `camera` plugin (Android mature; Windows sufficient for still capture).
  Used for worker photo v1 and face attendance v2, plus Aadhaar QR scanning.
- Local DB: **drift (SQLite)** + **SQLCipher** encryption. Sync queue in same DB.

## 3. Functional Scope

### v1 — Vendor mode (offline-capable)
- Register worker: Aadhaar **Secure-QR scan via camera** (auto-fill name/DOB/gender/
  address/photo from the signed QR — works fully offline, better than PDF flow) +
  manual fallback + Aadhaar PDF upload when online.
- Thumb **enrollment** via SecuGen (template captured + stored locally, uploaded on sync).
- Additional ID documents (camera capture / file).
- View own workers, deployment status. (Deployment creation: online-only in v1 —
  needs company approval state; revisit for v2.)
- Aadhaar is **mandatory** in the app flow (see §6 server prerequisites — enforce + dedup).

### v1 — Gate mode (kiosk, offline-capable)
- Device is registered to ONE company + location (gate/department/checkpoint —
  matches existing `location_type`/`location_name` model).
- 1:N thumb identify against the **local template cache** (workers currently deployed
  to that company), IN/OUT with the existing rules, queued locally, synced up.
- Exceptions view (who is inside) from local data.

### v2 (explicitly out of v1)
- Face attendance (insightface already ships in `pdf-service`; needs enroll/match
  endpoints + liveness anti-spoofing) — camera-only gates.
- Offline deployment management, payroll/muster exports, notifications.

## 4. Data & Sync Design

**Identity:** every client-created record carries a client-generated **UUID** and
`device_id`. Server stores both; retries are idempotent (unique index on UUID).

**Pull (server → device), delta sync via `updated_at` cursor + tombstones:**
- Vendor mode: own vendor profile, own workers, assignments, approved companies.
- Gate mode: workers + **encrypted fingerprint templates** currently deployed to the
  device's company (the 1:N cache), assignment windows, location config.

**Push (device → server), append-only queues:**
- New workers / documents / enrollments (vendor mode).
- Attendance events (gate mode): `{uuid, worker_id, type, marked_at, device_id,
  location_*, score, method}` — server accepts late events, re-validates IN/OUT
  sequence, may mark `is_valid=false` + `invalidation_reason` (columns already exist).

**Conflict rules:** master data = server wins; events = append-only (no conflicts);
duplicate worker detected at sync (Aadhaar hash) → server flags, app shows a
"resolve duplicate" task to the vendor. Clocks: device sends monotonic + wall time;
server records both `marked_at` (device) and `synced_at`.

## 5. Security Model (non-negotiable before pilot)

1. **HTTPS with a real domain** on the server — templates/passwords currently travel
   plain HTTP. Hard prerequisite.
2. **Device registration & auth:** device enrolls once (super admin approves),
   receives a scoped token (Sanctum). Templates only ever go to REGISTERED gate
   devices of that company; revocable server-side (revocation kills the sync token
   AND triggers local cache wipe on next contact).
3. **At rest:** SQLCipher DB; templates additionally encrypted with a per-device key
   (Windows DPAPI / Android Keystore).
4. **Scoping:** gate cache = only workers with an ACTIVE assignment to that company;
   vendor cache = only own vendor's data. Nothing global on any device.
5. Existing server items to fix first: Aadhaar mandatory + dedup hash (unique),
   pdf-service `raw_text` Aadhaar leak (H1), Worker `$fillable` (M1), login rate limiting.
6. Full audit: server logs sync batches per device; app keeps a local audit trail.

## 6. Server-Side Work Required (Laravel — extends existing API)

- `POST /api/devices/register` + approval flow; device-scoped tokens.
- `GET /api/sync/pull?since=…` (role/device-scoped bundles, incl. gate template bundle).
- `POST /api/sync/push` (batched, idempotent by UUID).
- Aadhaar: store salted hash of full number at extract-time for dedup (keep only
  masked number visible, per current design); unique index; duplicate-resolution API.
- Enforce Aadhaar mandatory on worker create (web + app paths).
- Keep `POST /attendance/identify` as the online/audit verify path; optionally wire a
  real server matcher later (NBIS) — no longer blocks the gate flow since matching
  moves on-device.

## 7. Phased Roadmap

| Phase | Deliverable | Size (rough) |
|---|---|---|
| 0 | Server prerequisites: HTTPS, Aadhaar mandatory+dedup, H1/M1/rate-limit, device registration + sync endpoints | 1–2 wk |
| 1 | Flutter skeleton: auth, role modes, drift+SQLCipher store, sync engine (pull/push, cursors, idempotency) | 2–3 wk |
| 2 | Gate mode v1: SecuGen Windows (SGIBIOSRV) + Android (platform channel, USB-OTG), local 1:N match, offline queue | 2–3 wk |
| 3 | Vendor mode v1: Aadhaar Secure-QR registration, thumb enrollment, docs, duplicate-resolution UX | 2–3 wk |
| 4 | Pilot at one site (1 gate kiosk + 1 vendor device), then hardening | 1–2 wk |
| 5 (v2) | Face attendance (liveness), offline deployments, exports | later |

## 8. Repo & Workflow (DECIDED: two repos)

Two repos under the `ITSea-Software-Solutions` org:

- **Attendance-Management-System** (this repo) — server (Laravel API), web portal
  (React), infra. The web portal keeps running and being used by all roles
  throughout app development; the only changes here for the client app are the
  new device/sync endpoints (§6).
- **Attendance-Management-System-Client** (new) — the Flutter app (Windows +
  Android). Own CI (APK / Windows builds), own release tags/versioning, clean
  clone for the Windows dev machine.

The **HTTP API is the contract** between the repos: API changes land in the server
repo first (§6), the app consumes them by version. A copy of this design doc lives
in the client repo and is authoritative for client-side decisions; §6 (server
work) stays authoritative here. Design changes: update the doc in the owning repo
first, then implement.

## 9. Open Questions (decide before/at Phase 1)

1. Windows gate stations: keep SGIBIOSRV service alongside the app, or invest in
   dart:ffi immediately? (Start: SGIBIOSRV.)
2. Template cache TTL / max offline window before a gate device must re-sync (e.g. 72h)?
3. Aadhaar Secure-QR signature verification on-device (UIDAI public key) — v1 or v2?
4. Kiosk lockdown method on Android (Lock Task Mode vs MDM)?
5. One Flutter app with role-switch vs two build flavors (vendor / gate)?
