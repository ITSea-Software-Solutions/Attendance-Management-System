# TrueCrew — Go-Live Plan

*Prepared 22 Aug 2026 · Platform v1.10.0 · Apps v0.9.34 · Owner: Sagar Mali*

The demo droplet (142.93.88.143) stays a **sales sandbox**. Production is a
fresh server with a domain, HTTPS, real credentials, and backups. Everything
below is ordered; each phase is doable in the stated window.

---

## Phase 0 — Already done (what we're launching with)

- Product: Aadhaar-verified registration, fingerprint + face attendance
  (offline-capable apps), deployment approvals, Live Board, visitor gate
  passes with WhatsApp approval, exports/reports, plans & billing (offline
  payment), role-scoped portal, full audit logging.
- Security hardening shipped: biometric matching audit (margins, quality
  floors, no fake matchers), engagement locks, per-gate scoping, dotfile
  probes blocked, dev ports closed, consent collection at registration and
  vendor-access.
- Zero-setup hardware: SecuGen HP20 plug-and-play on Android + Windows.
  Release pipeline with binary verification.

## Phase 1 — Production infrastructure (Week 1)

| Item | Choice | Cost (approx) |
|---|---|---|
| Domain | e.g. `truecrew.in` / `gettruecrew.com` | ₹800–1,200/yr |
| DNS + WAF/CDN | Cloudflare free plan (proxy on) | ₹0 |
| Server | DigitalOcean 4 GB / 2 vCPU droplet, Ubuntu 24.04, Mumbai/Bangalore region | ~₹2,000/mo ($24) |
| TLS | Let's Encrypt via certbot (or Cloudflare origin cert) | ₹0 |

**Production build differences vs demo (the critical list):**

1. Frontend served as a **static production build** (`npm run build` → nginx
   serves `dist/`) — no Vite dev server, no HMR. Nginx config gains the
   static-assets block and loses the 5173 proxy.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false` (kills stack traces and the
   dev reset-link), fresh strong `APP_KEY` (⚠ this keys the Aadhaar HMAC and
   fingerprint encryption — **back it up separately; losing it = losing
   biometric data**), strong MySQL/Redis passwords, `BIOMETRIC_SIM=false`,
   `AADHAAR_DEDUP=true`.
3. Fresh database — `php artisan migrate` from zero. Demo data stays on the
   demo droplet only.
4. Queue worker under compose `restart: always` (already is) + a weekly
   `docker system prune` cron.
5. Only ports 80/443 (+22 keyed SSH) published — same discipline as demo.

## Phase 2 — Backups & data protection (Week 1)

- **Nightly** `mysqldump` (cron) + **nightly tar of the private disk**
  (Aadhaar PDFs, photos, proofs) → pushed to DO Spaces / S3 bucket
  (~₹450/mo), 30-day retention.
- Weekly droplet snapshot (DO feature, ~20% of droplet cost).
- **Test one restore before onboarding the first paying customer.**
- Log rotation (docker `max-size` logging options).

## Phase 3 — Communications (Week 1–2)

- **Email (SMTP):** Brevo free tier (300/day) or Amazon SES. Set `MAIL_*`
  in `.env`; add SPF + DKIM records on the domain. Activates: password
  resets, vendor approvals, digests, weekly reports (Professional+).
- **WhatsApp Business Cloud API:** Meta Business verification + a dedicated
  number → set `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID`, and point the webhook
  at `https://<domain>/api/whatsapp/webhook` (verify token
  `WHATSAPP_WEBHOOK_VERIFY`). Activates: visitor YES/NO approvals +
  IN/OUT pings (Enterprise). *Until then visitor passes work with manual
  decisions — already live.*

## Phase 4 — Compliance (Week 2) — DPDP Act 2023

- Publish **Privacy Policy** and **Terms of Service** (drafts are in
  `frontend/public/legal/` — **have a lawyer review before launch**; they
  contain placeholders for legal entity name, address, grievance officer).
- Appoint a **grievance officer** (name + email in the policy — DPDP
  requirement).
- Our strong story (sales point too): raw Aadhaar numbers are **never
  stored** (masked + keyed hash only), fingerprints are encrypted at rest,
  worker consent is collected at registration, vendor consent at access
  request, every sensitive action is audit-logged, biometrics never go to
  third parties (self-hosted matching).
- Document a retention rule (suggested: biometric template deleted when a
  worker is deleted; attendance history retained 3 years).

## Phase 5 — Customer onboarding playbook (repeat per sale)

**Gate kit** (one per entry point): any Android phone or Windows PC +
SecuGen Hamster Pro 20 (~₹3,500). Optional Mantra MFS100 (~₹2,500) once its
SDK is bundled. *L1 devices (MFS110) don't work for attendance — by UIDAI
design; diagnostics explains this to anyone who plugs one in.*

30-minute setup: create company → gates/departments → HR + gate users →
invite/create vendors → install app on gate device → flip **hands-free
mode** → put the **Live Board** on a TV in the security cabin (the wow
moment). Train the guard (10 min) and HR (15 min: approvals, visitors,
manual OUT, reports). Check in daily for the first week.

## Phase 6 — Pricing & sales

- Plans already enforced in-product: **Trial** (free, 3 users/10 workers/3
  links) → **Professional** → **Enterprise**. Set the ₹ price on the plan
  cards before launch (decide; e.g. Professional ₹X,XXX/company/month,
  Enterprise ₹XX,XXX — offline payment/UPI/bank transfer is already the
  supported flow; add Razorpay later without removing offline).
- Sales assets: market flyer (`frontend/public/flyer.html`, print A5),
  client one-pager (`/docs/client-guide.html`), demo sandbox logins on the
  droplet, a 2-minute phone video of: worker thumb → verified card →
  Live Board updating.
- GST invoices manually at first (Zoho Invoice free tier works).

## Phase 7 — Support & operations

- A dedicated support WhatsApp number + stated hours on the flyer/portal.
- Uptime monitoring: UptimeRobot (free) on `https://<domain>/` and
  `/api/plans-public`.
- Release discipline stays: tag → CI → gated publish → Downloads page.
  Customers update apps from the Downloads page (link it in onboarding).
- Weekly release notes already maintained at `/release-notes.html`.

## Launch runbook (checklist)

- [ ] Buy domain, put DNS on Cloudflare
- [ ] Create production droplet; docker + compose; clone repo tag
- [ ] Production `.env` (debug off, sim off, dedup on, strong secrets);
      **back up APP_KEY offline**
- [ ] Static frontend build + production nginx config; certbot HTTPS
- [ ] `php artisan migrate` (fresh), create super admin with a strong password
- [ ] Backups cron (DB + private disk → Spaces) and one restore test
- [ ] SMTP creds + SPF/DKIM; send a test reset mail
- [ ] (When ready) WhatsApp Business creds + webhook verify
- [ ] Lawyer-reviewed Privacy Policy + ToS published; grievance officer named
- [ ] Prices set on plan cards; flyer printed
- [ ] Uptime monitor armed
- [ ] First customer onboarded with the playbook above

## Known gaps (tracked, not launch-blocking)

- Payment gateway (Razorpay) — offline payment stays supported regardless.
- Liveness/anti-spoofing for camera attendance (staffed gates + proof-photo
  cross-check mitigate; PAD model planned before unsupervised gates).
- OTP-based worker phone verification (manual attest exists).
- S3/offsite object storage for documents (local private disk + backups now).
