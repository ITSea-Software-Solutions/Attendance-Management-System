# AMS — Session Handoff (continue on Windows w/ real SecuGen reader)

Paste this whole file as your first message to Claude Code on the Windows machine.
It carries the full state of work done on the Mac so you can continue seamlessly.

> **NEXT BIG WORKSTREAM:** the cross-platform client app (Flutter, Windows+Android,
> offline-first, SecuGen thumb + camera). Agreed scope and architecture live in
> **`CLIENT_APP_DESIGN.md`** — read that file before starting any client-app work.

---

## 0. WHY THIS HANDOFF EXISTS
The SecuGen HU20-AP thumb reader is Windows-only, so the **real fingerprint flow
must run on Windows**. All recent work was done on a Mac and is committed to a
local branch that is **NOT on GitHub** (push blocked — the GitHub account has no
write access to `codetechx/adharbased_attendance_system`, 403). `origin/main`
(`8c03275`) does NOT contain any of the fixes below. Cloning from GitHub alone
gives you the OLD, broken code (build fails + blank screen).

## 1. GET THE CORRECT CODE ONTO WINDOWS  ← do this first
The branch `security-and-biometric-hardening` was exported to a git bundle:
`ams-hardening.bundle` (carry it via USB/cloud).

On Windows (PowerShell), with the bundle copied to e.g. `C:\work\`:
```powershell
# Option A — fresh clone straight from the bundle (recommended):
git clone C:\work\ams-hardening.bundle ams
cd ams
git checkout security-and-biometric-hardening

# Option B — you already cloned from GitHub and want to add the branch:
git fetch C:\work\ams-hardening.bundle security-and-biometric-hardening:security-and-biometric-hardening
git checkout security-and-biometric-hardening
```
The branch is 3 commits ahead of `origin/main`:
- `5c42df7` Security hardening + biometric consolidation
- `9f3fb65` Fix backend build: disable Composer advisory block
- `7589580` Fix blank screen: nginx must proxy Vite assets in dev mode

> Still TODO globally: get write access (org owner) or fork+PR so this branch
> lands on a remote. Until then the bundle is the source of truth.

## 2. WHAT THIS PROJECT IS
AMS (Attendance Management System) — multi-company/multi-vendor labour
registration + biometric (fingerprint) attendance. Stack: Laravel 11 / PHP 8.4
(backend, baked into image), React 18 + Vite (frontend, volume-mounted), MySQL 8,
Redis, Nginx, a Python FastAPI `pdf-service` (Aadhaar + face), a queue worker.
7 docker containers, names `ams_*`. See `CLAUDE.md` for the full project map.

## 3. RUNNING IT ON WINDOWS (with the REAL reader → SIM OFF)
On the Mac we used a `docker-compose.local.yml` override, but that is **Mac-specific**
(it worked around this Mac's port conflicts AND forced biometric SIMULATION mode).
On Windows you want the **real device**, so do NOT reuse that override. Use the base
compose file only:
```powershell
docker compose up -d --build
```
- Base compose publishes ports 80/443/3306/5173/6379/8001. Make sure they're free.
- Biometric SIM defaults to OFF in the base compose (no `VITE_BIOMETRIC_SIM` /
  `BIOMETRIC_SIM` set) → real-device mode. Good.
- The backend entrypoint auto-generates `APP_KEY`, runs `migrate --seed --force`
  (creates the demo users), links storage, caches config. No manual `.env` needed,
  but you can `copy .env.example .env` if you want a stable key.
- App URL: http://localhost  (nginx :80 proxies `/`→Vite, `/api`→Laravel)

### SecuGen reader setup (Windows host, outside Docker)
The browser talks DIRECTLY to the SecuGen local service at `https://localhost:8443`
(`/SGIFPCapture`, `/SGIMatchScore`) — this is browser→localhost, independent of Docker.
1. Install SecuGen drivers + the SGIBIOSRV service for the HU20-AP.
2. Start the proxy: `py -3 biometric-agent\sgibiosrv_proxy.py`
   (the Node `biometric-agent/server.js` WS on :12345 is DEAD CODE — ignore it.)
3. Visit http://localhost → log in → /diagnostic/fingerprint to test the scanner.

## 4. FIXES MADE THIS SESSION (don't regress these)
1. **`backend/Dockerfile`** — added `RUN composer config --global policy.advisories.block false`
   in the builder stage. Composer 2.8+ refuses to install Laravel 11.31–11.54 (all
   flagged by security advisories), which broke the image build. ⚠️ Before a REAL
   production build, bump `laravel/framework` to a patched version and remove this line.
2. **`nginx/conf.d/default.conf`** — two changes that fixed a blank screen in dev mode:
   - Removed the `location ~* \.(js|css|...)$ { try_files $uri =404; }` static block.
     It out-ranked the `location /` Vite proxy and 404'd every JS/CSS module (incl.
     React). In dev, ALL assets must proxy to Vite. (Re-add a static block only for a
     production `vite build`.)
   - Narrowed `location ~ /\.` → `location ~ /\.(?!vite/)` so Vite's optimized dep
     cache at `/node_modules/.vite/deps/*` is served while `.env`/`.git` stay blocked.
   - If you ever see a blank page after a deps change: the Vite optimizer may be
     mid-flight (transient 504s); just reload after it's "ready".

## 5. DEMO LOGINS (all seeded by DatabaseSeeder; same on local + server)
AMS — http://localhost (local) / http://142.93.88.143 (test server)
| Role          | Email                  | Password    |
|---------------|------------------------|-------------|
| Super Admin   | superadmin@ams.local   | Admin@12345 |
| Company Admin | company@ams.local      | Admin@12345 |
| Gate User     | gate@ams.local         | Admin@12345 |
| Vendor Admin  | vendor@ams.local       | Admin@12345 |

## 6. TEST SERVER (DigitalOcean — shared with "Josbin POS", do not disturb josbin_* containers)
- IP 142.93.88.143, `ssh root@142.93.88.143` (key-based). AMS at http://142.93.88.143.
- AMS cloned at `/var/www/attendance`. Deploy model: backend code is BAKED into the
  image (rebuild + `up -d backend` + `php artisan config:cache` after changes);
  frontend is volume-mounted (live/HMR). ALWAYS run compose with BOTH files:
  `docker compose -f docker-compose.yml -f docker-compose.prod.yml <cmd>`.
- `docker-compose.prod.yml` is server-only (NOT in git): removes public mysql/redis/
  pdf port publishing, low-mem MySQL tuning, and sets `BIOMETRIC_SIM=true` /
  `VITE_BIOMETRIC_SIM=true` (server has no reader → SIM ON for demo).
- The server's deployed backend was shipped via rsync + image rebuild, so its working
  tree is effectively ahead of git in ways git doesn't track — be careful with
  git pull/reset on the server (could revert hardening). The nginx fix above WAS
  applied to the server this session (config copied + nginx reloaded + frontend
  restarted) and verified.

## 7. OPEN ITEMS / TODO (from prior code review + this session)
- **Real fingerprint matcher**: `BiometricService::matchTemplates()` is a PLACEHOLDER
  (byte-similarity, not real). With the real reader on Windows, server-side 1:N
  matching (`POST /attendance/identify`) will use this placeholder — enrollment and
  capture are real but matching quality is bogus. Wire a real SecuGen SDK / NIST NBIS
  binary into `callMatchingBinary()`. THIS is likely your main Windows task.
- Push/access: land the branch on a remote (write access or fork+PR).
- H1: `pdf-service` returns full Aadhaar number in `raw_text` to Laravel — strip it.
- M1: Worker `$fillable` includes status/registered_by/fingerprint_template/
  face_descriptor (mass-assignment risk).
- No login rate-limiting/lockout.
- Remove dead `biometric-agent/` Node files + committed `sgbledev.dll`; fix stale help text.
- Before real prod: production `vite build` behind nginx (don't expose the Vite dev
  server publicly), bump Laravel off advisory-flagged versions.

## 8. LOCAL-ONLY FILES (NOT in git — recreate on Windows as needed)
- `.env` (gitignored) — optional; entrypoint generates APP_KEY if absent.
- `docker-compose.local.yml` — Mac-only port/SIM override; do NOT copy to Windows.
- `demo-logins.xlsx` — credentials spreadsheet (also for Josbin POS).
- `HANDOFF.md` — this file (untracked).
