# TrueCrew — Every Worker Verified

> Product name: **TrueCrew** (हाज़री — "attendance"). Internal/technical codename remains **AMS** (repo, containers `ams_*`, API paths) — do not rename infrastructure.

Enterprise-grade multi-company, multi-vendor labor registration and biometric attendance system built on Laravel + React.

---

## Architecture

### System overview (v1.0 web + v0.9 client apps)

```mermaid
flowchart LR
  subgraph Clients
    WEB["Web portal (React+Vite)\nsuper admin · company admin\nvendor · gate"]
    APP["Flutter app (client/)\nAndroid + Windows\nvendor + gate, OFFLINE-first"]
    SG["SecuGen scanner\nSGIBIOSRV localhost:8443\n(gate PCs / Windows)"]
  end
  subgraph Server["DigitalOcean droplet (docker compose)"]
    NG[nginx :80]
    BE["Laravel 11 API\n(auth, workers, attendance,\nsync/pull + sync/push)"]
    MY[(MySQL 8)]
    RD[(Redis)]
    PY["pdf-service (FastAPI)\nAadhaar extract · insightface"]
    Q[queue worker]
  end
  WEB -->|/  + /api| NG
  APP -->|"/api (login online; then offline\nqueue → idempotent sync by UUID)"| NG
  WEB -.->|fingerprint capture| SG
  APP -.->|"Windows capture (v0.9)\nAndroid USB-OTG SDK (v1.0)"| SG
  NG --> BE
  BE --> MY
  BE --> RD
  BE --> PY
  Q --> RD
  APP <-->|"pull: workers/assignments/attendance\npush: registrations + marks"| BE
```

- **Offline model:** app keeps an encrypted local SQLite copy; events (registrations,
  attendance marks) queue with client UUIDs and push idempotently; master data pulls
  role-scoped bundles — **server wins** on master data, events are append-only.
- **Biometrics:** web + Windows app capture via SGIBIOSRV; demo/sim mode works with
  no hardware. Android USB-OTG SecuGen SDK and face (InsightFace) are the next phases
  — full matrix in `CLIENT_APP_DESIGN.md`.

### Repository layout

```
attendance_system/
├── client/                     # Flutter app (Android + Windows) — offline-first
├── docker-compose.yml          # All services wired together
├── init.sh                     # One-time setup script
├── .env.example                # Copy to .env before starting
│
├── nginx/                      # Reverse proxy config
├── docker/mysql/               # MySQL init SQL + seed
│
├── backend/                    # Laravel 11 (PHP 8.3 + FPM)
│   ├── app/Http/Controllers/   # API controllers (per resource)
│   │   ├── AttendanceController.php     # mark, templates, daily-summary, exceptions
│   │   ├── AadhaarController.php        # extract, upload, download (role-scoped)
│   │   ├── WorkerController.php         # CRUD + fingerprint + stats + photo
│   │   ├── WorkerIdDocumentController.php # store/download worker docs (images + PDF)
│   │   ├── WorkerAssignmentController.php # deploy workers, current/previous filter
│   │   └── ...
│   ├── app/Models/             # Eloquent models
│   ├── app/Services/           # AuditService, BiometricService
│   ├── database/migrations/    # Full schema history
│   └── routes/api.php          # All API routes
│
├── frontend/                   # React 18 + Vite + Tailwind CSS
│   └── src/
│       ├── pages/
│       │   ├── companies/      # CompanyList
│       │   ├── vendors/        # VendorList (role-aware tabs), VendorApproval, VendorCompanyAccess
│       │   ├── workers/        # WorkerList, WorkerDetail, WorkerRegister, WorkerAssign
│       │   ├── users/          # UserList
│       │   └── attendance/     # AttendanceMark, AttendanceList (daily summary), Exceptions
│       └── components/         # Sidebar, FingerprintCapture, etc.
│
├── pdf-service/                # Python FastAPI — Aadhaar PDF extraction
│   └── main.py                 # PDF text + photo extraction via pdfplumber
│
└── biometric-agent/            # Local Windows fingerprint bridge
    ├── sgibiosrv_proxy.py      # HTTP proxy → SGIBIOSRV (https://localhost:8443)
    └── winbio_server.py        # Alternative: Windows Biometric Framework (WBF)
```

---

## Quick Start

### Prerequisites
- Docker Desktop (Windows / Mac / Linux)
- Git Bash or WSL (Windows users)

### 1. First-time setup
```bash
cp .env.example .env
bash init.sh
```

### 2. Start / Stop
```bash
# Start all services
start.bat          # Windows
docker compose up -d   # Any OS

# Stop
down.bat
```

### 3. Access the application

| Service     | URL                   |
|-------------|-----------------------|
| App (UI)    | http://localhost      |
| API         | http://localhost/api  |
| PDF Service | http://localhost:8001 |

### Default Login Credentials

| Role          | Email                | Password    |
|---------------|----------------------|-------------|
| Super Admin   | superadmin@ams.local | Admin@12345 |
| Company Admin | company@ams.local    | Admin@12345 |
| Gate User     | gate@ams.local       | Admin@12345 |
| Vendor Admin  | vendor@ams.local     | Admin@12345 |

---

## Core Business Flow

```
Super Admin
  ├── Creates Company  (with optional company_admin login)
  └── Creates Vendor   (with optional vendor_admin login)
          │
          ▼
Vendor Admin
  └── Sends access request to Company
          │
          ▼
Company Admin
  └── Approves the Vendor
          │
          ▼
Vendor Admin / Operator
  └── Registers Workers
        ├── Uploads Aadhaar PDF (auto-extracted via Python)
        ├── Enrolls Fingerprint (via SecuGen scanner)
        └── Deploys Worker to Company (WorkerAssign)
          │
          ▼
Company Admin
  └── Creates Gate Users (one per entry point)
          │
          ▼
Gate User (at company site)
  └── Marks IN / OUT via fingerprint scan
        → Attendance logged against: Worker ↔ Vendor ↔ Company
          │
          ▼
Vendor Admin / Company Admin
  └── Views attendance in daily-summary view
      Click worker row → Worker Detail analytics page
      (stats, monthly breakdown, deployment history)
```

---

## Roles & Permissions

| Role              | What they can do                                                              |
|-------------------|-------------------------------------------------------------------------------|
| `super_admin`     | Full access — create companies, vendors, all users, view everything           |
| `company_admin`   | Approve/reject vendors, create gate users, view attendance at their company   |
| `company_gate`    | Mark fingerprint attendance at their company only                             |
| `vendor_admin`    | Register/manage workers, deploy to companies, view worker attendance          |
| `vendor_operator` | Register workers only                                                         |

---

## Key Pages & Features

### Workers
- **WorkerList** (`/workers`) — All / Current / Previous tabs; clickable rows → Worker Detail; ID document column with download links for Aadhaar PDF and other docs
- **WorkerDetail** (`/workers/:id`) — Analytics page: total days, avg hours, monthly breakdown. Company users see only their company's data. Vendor users get a company dropdown to filter per-company; includes deployment history
- **WorkerRegister** (`/workers/register` or `/workers/:id/edit`) — 4-step wizard; edit mode shows existing Aadhaar/document download links
- **WorkerAssign** (`/workers/assign`) — Deploy workers to companies; Current / Previous / All tabs; can cancel even locked deployments if worker is currently checked OUT

### Attendance
- **AttendanceList** (`/attendance`) — Daily summary grouped view: per worker per day shows First IN, Last OUT, duration, and status (Inside / Done / Incomplete). All / Current Workers / Previous Workers tabs. Row click → Worker Detail
- **AttendanceMark** (`/attendance/mark`) — Fingerprint IN/OUT with SecuGen scanner
- **Exceptions** (`/attendance/exceptions`) — Workers currently checked IN without an OUT

### Vendors
- **VendorList** (`/vendors`) — Super admin: full global list + create/edit. Company users: approval-status tabs (Approved / Pending / Suspended / Rejected / All) showing only their company's vendor relationships
- **VendorApproval** (`/vendors/approval`) — Pending / Approved / Rejected / Suspended tabs with counts; approve, reject with reason, suspend, re-approve
- **VendorCompanyAccess** (`/vendors/company-access`) — Vendor-side: request access to companies, track statuses

---

## Aadhaar Integration

1. On Worker Register page, click **Open UIDAI Portal**
2. Worker logs in on UIDAI site, completes OTP, downloads masked Aadhaar PDF
3. Vendor uploads the PDF — Python service extracts: name, DOB, gender, address, PIN, photo
4. Form auto-fills; vendor reviews and saves
5. PDF stored on private disk (never web-accessible); served via authenticated download endpoint
6. Company users can download a worker's Aadhaar PDF if the worker has ever been deployed to or attended at their company

**PDF Password format:** first 4 letters of name (uppercase) + birth year
Example: Name "Narendra", Born 1955 → password `NARE1955`

---

## Fingerprint Integration (SecuGen SGIBIOSRV)

### Gate PC Setup
1. Install SecuGen SGIBIOSRV (included in FDx SDK)
2. Connect SecuGen device (Hamster Pro, etc.)
3. Start the SGIBIOSRV service
4. On first use, open `https://localhost:8443` in browser and accept the certificate

### How matching works
```
Gate PC Browser
  → POST https://localhost:8443/SGIFPCapture   (capture live fingerprint)
  → GET  /api/attendance/worker-templates      (fetch all enrolled templates for approved vendors)
  → POST https://localhost:8443/SGIMatchScore  (1:N match in parallel, per worker)
  → Best match above score 40/200 → confirm → POST /api/attendance/mark
```

---

## Worker Deployment (WorkerAssign)

Workers can be explicitly deployed to specific companies with a date range. The deployment system:
- Sets `is_locked = true` on first attendance (prevents premature cancel)
- Cancellation allowed even when locked, **provided the worker is not currently checked IN**
- `/workers` and `/attendance` endpoints support `deployment=current` or `deployment=previous` param for tab filtering

---

## Docker Services

| Container         | Purpose                    | Port            |
|-------------------|----------------------------|-----------------|
| `ams_nginx`       | Reverse proxy              | 80              |
| `ams_backend`     | Laravel PHP-FPM            | 9000 (internal) |
| `ams_frontend`    | React/Vite dev server      | 5173            |
| `ams_mysql`       | MySQL 8                    | 3306            |
| `ams_redis`       | Cache & queue broker       | 6379            |
| `ams_pdf_service` | Python Aadhaar extractor   | 8001            |
| `ams_queue`       | Laravel queue worker       | —               |

### Pushing backend changes (no volume mount)
```bash
# Copy changed file(s) into the running container
docker cp backend/app/Http/Controllers/WorkerController.php \
  ams_backend:/var/www/html/app/Http/Controllers/WorkerController.php

# Clear Laravel's opcode cache
docker exec ams_backend php artisan optimize:clear
```

---

## Key API Endpoints

```
POST /api/auth/login                                      Login (returns Sanctum token)
GET  /api/auth/me                                         Current user info

GET  /api/companies                                       List companies
POST /api/companies                                       Create company (super_admin)
GET  /api/companies/{id}/vendors                         Company-vendor relationships (with pivot status)
POST /api/companies/{id}/vendors/{vid}/approve           Approve vendor
POST /api/companies/{id}/vendors/{vid}/reject            Reject vendor (requires reason)
POST /api/companies/{id}/vendors/{vid}/suspend           Suspend vendor

GET  /api/vendors                                         List vendors (scoped by role)
POST /api/vendors/{id}/request-company/{cid}              Vendor requests company access
GET  /api/vendors/{id}/available-companies                All companies + request status

GET  /api/workers                                         List workers (?deployment=current|previous)
POST /api/workers                                         Register worker
GET  /api/workers/{id}                                    Worker detail
GET  /api/workers/{id}/stats                              Worker analytics (?company_id=X)
GET  /api/workers/{id}/photo                              Serve worker photo (named: worker.photo)
POST /api/workers/{id}/activate                           Activate worker
POST /api/workers/{id}/deactivate                         Deactivate worker
POST /api/workers/{id}/fingerprint                        Enroll fingerprint

GET  /api/workers/{id}/id-documents                       List worker ID documents
POST /api/workers/{id}/id-documents                       Add ID document (image or PDF)
GET  /api/workers/{id}/id-documents/{doc}/download        Download ID document file

POST /api/aadhaar/extract                                 Extract data from Aadhaar PDF (no storage)
POST /api/aadhaar/upload/{worker}                         Upload Aadhaar PDF (stored securely)
GET  /api/aadhaar/download/{worker}                       Download stored Aadhaar PDF (role-scoped)

GET  /api/users                                           List users (role-scoped)
POST /api/users                                           Create user

GET  /api/attendance/worker-templates                     Fingerprint templates for approved vendors
POST /api/attendance/mark                                 Mark IN/OUT
GET  /api/attendance                                      Attendance log (?deployment=current|previous)
GET  /api/attendance/daily-summary                        Grouped daily summary (per worker per day)
GET  /api/attendance/exceptions                           Workers with IN but no OUT
GET  /api/attendance/report                               Date-range report

GET  /api/workers/assign                                  Worker assignments (?deployment=current|previous)
POST /api/workers/assign                                  Create assignment
DELETE /api/workers/assign/{id}

POST /api/signup                                   ← PUBLIC SaaS signup (company|vendor, starts on Trial)
GET  /api/plan                                     ← org plan + usage + catalogue
POST /api/plan/upgrade-request
GET  /api/admin/subscriptions                      ← super admin
POST /api/admin/subscriptions/set-plan
POST /api/admin/plan-requests/{id}/decide
GET  /api/sync/pull      POST /api/sync/push       ← client app offline sync (idempotent)
                          Cancel assignment
```

---

## Security

- **Authentication:** Laravel Sanctum token (7-day expiry)
- **Authorization:** Role middleware on every route + controller-level scoping
- **Aadhaar:** Only last 4 digits stored in DB; full number never persisted
- **Fingerprint templates:** AES-encrypted at rest via Laravel `encrypt()`
- **Aadhaar PDFs:** Stored on private disk (not web-accessible), served via authenticated download
- **ID documents:** Stored on private disk; company users may download only for workers deployed at their company
- **Audit log:** Every sensitive action (worker created, fingerprint enrolled, Aadhaar downloaded, attendance marked) logged with user ID, timestamp, and IP
- **HTTPS:** Required in production — add SSL certificate to nginx config

---

## Production Deployment Checklist

- [ ] Set `APP_ENV=production`, `APP_DEBUG=false` in `.env`
- [ ] Run `php artisan key:generate` for a unique `APP_KEY`
- [ ] Use strong passwords for DB, Redis, and all user accounts
- [ ] Add SSL certificates to `nginx/` config
- [ ] Switch file storage to S3: `FILESYSTEM_DISK=s3`
- [ ] Enable Laravel Horizon for queue monitoring
- [ ] Restrict DB port (3306) — do not expose publicly
- [ ] Set up automated MySQL backups
- [ ] Test fingerprint enrollment and matching end-to-end before go-live


Role	Email	Password
Super Admin	superadmin@ams.local	Admin@12345
Company Admin	company@ams.local	Admin@12345
Gate User	gate@ams.local	Admin@12345
Vendor Admin	vendor@ams.local	Admin@12345