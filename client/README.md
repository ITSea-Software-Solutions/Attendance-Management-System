# AMS Client App (Flutter — Android + Windows)

Offline-first vendor registration + gate attendance. Design: `../CLIENT_APP_DESIGN.md`.

## v0.9.0-preview — what works
- Login against any AMS server (URL on the login screen; default demo server).
- Role modes: vendor (worker list + offline registration w/ mandatory Aadhaar),
  gate/company (fingerprint attendance IN/OUT, currently-inside, activity).
- Offline: local SQLite cache; registrations & marks queue and sync
  idempotently (client UUIDs) via `/api/sync/push`; role-scoped `/api/sync/pull`.
- Biometrics: SIMULATION everywhere (like the server demo); on Windows desktop,
  real SecuGen capture via SGIBIOSRV (`https://localhost:8443`) when running.

## Not yet (v1.0)
Android USB-OTG SecuGen SDK, on-device 1:N matcher + template cache,
Aadhaar Secure-QR scan, kiosk lockdown, photo capture, face (v2).

## Build
```bash
flutter pub get
flutter build apk --release          # Android (any OS)
flutter build windows --release      # Windows ONLY on a Windows machine
```
APK output: `build/app/outputs/flutter-apk/app-release.apk` →
copy to `../frontend/public/downloads/` to publish on the download page.
