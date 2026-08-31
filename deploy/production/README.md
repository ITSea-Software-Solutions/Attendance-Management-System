# TrueCrew — Production Deployment Pack

Everything here makes launch day mechanical. The demo droplet stays a sales
sandbox; production is a fresh server + domain.

## What's in this folder

| File | Purpose |
|---|---|
| `.env.production.example` | Every launch variable, including all dormant credentials, with [REQUIRED] markers |
| `nginx-production.conf` | Production nginx: serves the BUILT frontend (no dev server), TLS, dotfile deny, gzip |
| `setup.sh` | Server bootstrap — read it, then run it step by step on a fresh Ubuntu 24.04 box |

## Launch-day order (matches GO_LIVE_PLAN.md)

1. Point the domain's A record at the new server (Cloudflare proxy ON).
2. SSH in, run `setup.sh` sections 1–3 (docker, clone, env).
3. Fill `backend/.env` from `.env.production.example`; `php artisan key:generate`;
   **back up APP_KEY offline immediately**.
4. Run sections 4–6 (TLS cert, frontend build, stack up + migrate).
5. Create the super admin, then run the verifier:
   `docker exec ams_backend php artisan truecrew:test-comms`
   — every yellow line names exactly which credential is still missing;
   greens mean that channel is live. Add creds anytime; re-run to confirm.
6. Install the backup cron (section 7) and test one restore.

## Dormant features & their switches (no code changes ever needed)

| Feature | Activates when | Until then |
|---|---|---|
| Real emails | `MAIL_*` set | mails land in laravel.log |
| Worker OTP SMS | `MSG91_AUTHKEY` + template | demo code shown in debug mode |
| WhatsApp (visitors, pings) | `WHATSAPP_TOKEN` + `WHATSAPP_PHONE_ID` + Meta webhook → `/api/whatsapp/webhook` | messages logged (debug); manual visitor decisions |
| Online payment | `RAZORPAY_KEY_ID/SECRET` (+ prices) | button hidden; offline payment fully works |
| Prices on cards/orders | `PRICE_*_INR` | placeholders shown |
| Face anti-spoofing | PAD model file + `FACE_PAD_THRESHOLD` | staffed-gate policy + proof cross-check |
