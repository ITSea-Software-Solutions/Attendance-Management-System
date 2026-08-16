# TrueCrew — Windows Laptop Handoff (2026-08-16)

Read this + `CLAUDE.md` (auto-loaded by Claude Code) at the start of a session
on a new machine. `CLIENT_APP_DESIGN.md` has the client-app architecture.

---

## What this is

**TrueCrew** (repo/internal name AMS) — multi-company, multi-vendor worker
registration + biometric attendance SaaS.
- **Server**: Laravel 11 API + React web portal + MySQL/Redis + Python
  pdf-service (Aadhaar extraction, ArcFace), Docker on the droplet
  `root@142.93.88.143` (`/var/www/attendance`). Web portal is the FALLBACK UI —
  the apps are the product.
- **Apps** (`client/`, Flutter): Android + Windows. Offline-first (SQLite),
  idempotent sync, full registration (Aadhaar PDF + consent + fingerprint +
  photo/face), gate attendance (fingerprint 1:N + camera face), diagnostics.
- **Biometrics**: SecuGen HU20 (Hamster Pro 20) is the test device, drivers
  kept vendor-neutral. Android: SecuGen FDx SDK **bundled in the APK**
  (v0.9.13+) — plug in via OTG, allow, scan. Windows: direct FFI to
  `sgfplib.dll`, auto-discovered from exe dir / System32 /
  `C:\Program Files\SecuGen\**` (driver install drops it there).
  Phone's built-in fingerprint sensor can NEVER identify workers (OS-sealed);
  phone-native worker biometric = camera face match.

## Current state (2026-08-16)

- Web **v1.2.0** live on droplet: SaaS signup/plans/billing, vendor approvals,
  Aadhaar-only registration (masked-PDF manual-entry path), attendance daily
  summary + exceptions, **CSV/print exports**, **per-gate scoping**,
  **self-service password reset**, email notifications (mailer=log until SMTP).
- Apps **v0.9.13-preview** on the download page (`/download.html`):
  Android APK with SecuGen SDK inside; Windows zip (CI-built).
- Tags: `v1.2.0`, `app-v0.9.13-preview`. Branch: `security-and-biometric-hardening`.

**Pending / next:**
1. **REAL capture test on HU20** — Android (v0.9.13) and Windows (v0.9.12+).
   Never yet verified on real hardware; everything else E2E-tested with SIM.
2. **Windows SDK DLL bundling** — waiting on "FDx SDK Pro for Windows" link
   from SecuGen's form (https://secugen.com/request-free-software/). Then: put
   x64 DLLs in `client/windows/` packaging + CI zip → fully self-contained
   (only the USB driver remains a per-machine install; consider shipping the
   driver installer inside the zip too).
3. SMTP creds on droplet (`MAIL_MAILER` etc. in backend/.env) → real reset
   emails + notifications.
4. Domain + HTTPS (runbook: `docs/HTTPS_SETUP.md`), ToS/Privacy placeholders
   (`[LEGAL ENTITY NAME]`, grievance email in `frontend/public/terms.html`,
   `privacy.html`).
5. Payroll — deferred by decision until attendance ships properly.

## Windows laptop setup (one-time)

```powershell
# 1. Tools: Git, Claude Code for Windows, Flutter SDK (stable channel),
#    Visual Studio 2022 Community + "Desktop development with C++" workload
#    (required by `flutter build windows`), Android Studio (only to build APKs).
# 2. Code (your GitHub user has access):
git clone https://github.com/ITSea-Software-Solutions/Attendance-Management-System.git truecrew
cd truecrew
git checkout security-and-biometric-hardening
# 3. App deps:
cd client
flutter pub get
flutter doctor          # fix anything red for "Windows desktop"
```

**Droplet access (only needed for deploys):** generate a key
(`ssh-keygen -t ed25519`) and append the `.pub` line to
`/root/.ssh/authorized_keys` on the droplet (from a machine that already has
access, e.g. the Mac: `cat key.pub | ssh root@142.93.88.143 'cat >> ~/.ssh/authorized_keys'`).
No credentials live in this repo.

**Docker Desktop is OPTIONAL** — only for running the whole server stack
locally (`docker compose -f docker-compose.yml -f docker-compose.local.yml up`).
Simpler: point apps at the droplet (`lib/core/config.dart` server URL) and
skip local Docker on Windows entirely.

## The fast loop on Windows (why the laptop is better for app work)

With the HU20 plugged into the laptop:

```powershell
cd client
flutter run -d windows      # live app + REAL scanner, hot-reload on save
flutter build windows       # release build → build\windows\x64\runner\Release\
flutter build apk           # APK (needs Android Studio/SDK installed)
```

No more CI round-trips for Windows testing — `flutter run -d windows` with the
real device is the loop the scanner work has been missing. Diagnostics screen
→ scanner card shows exactly which DLL loaded / what's missing.

## Deploy & release (same from any OS)

- Server: rsync changed files to `root@142.93.88.143:/var/www/attendance/`,
  then `docker compose -f docker-compose.yml -f docker-compose.prod.yml build
  backend queue-worker && up -d`, **restart nginx last** (it caches upstream
  IPs → 502 otherwise). Frontend + docs rsync = live instantly. Backend is a
  BAKED image (local dev too: `docker cp` + `php artisan optimize:clear`).
- App release: bump `client/pubspec.yaml` + `lib/core/config.dart` → build APK
  → replace `frontend/public/downloads/truecrew-android-vX.Y.Z-preview.apk` →
  bump `frontend/public/download.html` + `frontend/src/pages/Downloads.jsx` →
  rsync both + APK to droplet → commit → tag `app-vX.Y.Z-preview` + GitHub
  release → CI (`.github/workflows/build-apps.yml`) attaches APK + Windows zip
  → download the zip, replace `truecrew-windows-x64-preview.zip` locally + on
  droplet. Binaries are gitignored — never commit APK/zip/exe.
- Web release: tag `vX.Y.Z` + GitHub release.

## Mac vs Windows — what actually matters

| Work | Where |
|------|-------|
| Windows app + scanner testing | **Windows laptop** (real device + local builds; Mac cannot build Windows exes) |
| Android app | Either (build on both; phone testing wherever the phone is) |
| Backend/frontend/server | Either — pure edit + rsync |
| Local full-stack Docker | Mac has it running; optional on Windows |
| Claude context | Carried by `CLAUDE.md` + this file in the repo — sessions on any machine pick it up. Keep both updated; always `git pull` before starting. |

Demo logins: `demo-logins.xlsx` (repo root, untracked) / seeded users use
`Admin@12345`. Aadhaar PDF password format: first 4 letters of name UPPERCASE
+ birth year.
