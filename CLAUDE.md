# AMS — Project Context for Claude

This file is read automatically at the start of every Claude Code session.
Keep it updated whenever features are added, changed, or removed.

---

## What This Project Is

**Hazri** (brand; internal codename **AMS** — keep all technical identifiers: `ams_*` containers, API routes, storage keys) — enterprise multi-company, multi-vendor labor registration and biometric attendance system.

Workers are registered by vendor companies, deployed to client companies, and mark attendance via fingerprint scanning at site gates. The system tracks who is where, when, and for how long.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11, PHP 8.4, PHP-FPM |
| Frontend | React 18, Vite, Tailwind CSS, TanStack Query v5 |
| Database | MySQL 8 |
| Cache/Queue | Redis |
| Reverse proxy | Nginx |
| PDF extraction | Python FastAPI (`pdf-service/`) via `pdfplumber` |
| Biometric | SecuGen SGIBIOSRV (Windows service, `https://localhost:8443`) |
| Auth | Laravel Sanctum (Bearer token, 7-day expiry) |
| Containerisation | Docker Compose (7 containers) |

---

## Roles

| Role | Key abilities |
|------|--------------|
| `super_admin` | Platform owner — creates companies/orgs, manages ALL users, Subscriptions page (plans/upgrades) |
| `company_admin` | **Creates & approves vendors** (auto-approved for own company), creates gate users + vendor-admin logins, views company attendance/workers, Plan & Billing |
| `company_gate` | Marks IN/OUT fingerprint attendance only |
| `vendor_admin` | Registers workers, deploys to companies, views worker attendance |
| `vendor_operator` | Registers workers only |

**Backend helpers on `User` model:** `isSuperAdmin()`, `isCompanyUser()`, `isVendorUser()`

---

## Business Flow (End-to-End)

```
0. SaaS: anyone signs up at /signup as Company OR Vendor (starts on Trial;
   paid plan choice files an offline-payment upgrade request)
1. Super Admin creates Companies (or they self-signup); Company Admin creates
   their own vendors (auto-approved) — or vendors self-signup and request access
2. Vendor Admin → Company Access page → sends access request
3. Company Admin → Vendor Approvals page → approves vendor
4. Vendor Admin → Register Worker → Aadhaar PDF upload + fingerprint enrollment
5. Vendor Admin → Deploy Workers → assigns worker to company with date range
6. Company Admin → Users → creates Gate User accounts (one per entry point)
7. Gate User → Mark Attendance → fingerprint scan → IN / OUT recorded
8. All roles → view attendance in daily-summary grouped view
9. Click any worker row → Worker Detail analytics page
```

---

## Docker Architecture

```
Browser → ams_nginx (:80)
  /     → ams_frontend  (React/Vite :5173)  [VOLUME MOUNTED — live reload]
  /api  → ams_backend   (Laravel PHP-FPM :9000) [BAKED IMAGE — needs docker cp]
             ↕ ams_mysql (:3306)
             ↕ ams_redis (:6379)
             ↕ ams_pdf_service (:8001)  [Python FastAPI]
         ams_queue  [Laravel queue:work]
```

**CRITICAL:** Backend PHP is baked into the image — not volume-mounted.
Every backend change requires:
```bash
docker cp backend/app/Http/Controllers/SomeController.php \
  ams_backend:/var/www/html/app/Http/Controllers/SomeController.php
docker exec ams_backend php artisan optimize:clear
```
Frontend changes are instant (volume mount, Vite HMR).

---

## Database — Key Tables

| Table | Purpose |
|-------|---------|
| `companies` | Client companies |
| `vendors` | Contractor/vendor companies |
| `company_vendors` | Pivot — company↔vendor relationship + `status` (pending/approved/rejected/suspended) |
| `users` | All users with `role` + `company_id` or `vendor_id` |
| `workers` | Registered workers — `status`, `fingerprint_template` (AES encrypted), `aadhaar_pdf_path`, `fingerprint_enrolled_at` |
| `worker_id_documents` | Additional ID documents (Aadhaar, PAN, etc.) — `document_path` on private disk |
| `worker_assignments` | Worker↔company deployments — `start_date`, `end_date`, `status`, `is_locked` |
| `attendance_logs` | Each IN/OUT event — `type` (IN/OUT), `worker_id`, `company_id`, `marked_at`, `gate`, `fingerprint_score` |
| `audit_logs` | Every sensitive action with user, action string, model, IP |

**Key design decisions:**
- `workers.aadhaar_pdf_path` is in `$hidden` — use `has_aadhaar_pdf` accessor (in `$appends`) to check presence
- Aadhaar PDF stored via `AadhaarController` → `workers.aadhaar_pdf_path` (NOT in `worker_id_documents`)
- Other docs stored via `WorkerIdDocumentController` → `worker_id_documents.document_path`
- Both on `Storage::disk('private')` — never web-accessible, served via authenticated download endpoints
- `worker_assignments.is_locked = true` on first attendance mark; cancel still allowed if latest log type is OUT
- `fingerprint_template` AES-encrypted via Laravel `encrypt()`/`decrypt()`

---

## Frontend Pages (src/pages/)

| Page | Route | Who sees it | Notes |
|------|-------|-------------|-------|
| `Dashboard` | `/dashboard` | all | Today's summary stats |
| `CompanyList` | `/companies` | super_admin | CRUD companies + company admin creation |
| `VendorList` | `/vendors` | super_admin, company_admin, company_gate | Super admin: global list + create. **Company users: approval-status tabs (Approved/Pending/Suspended/Rejected/All)** fetched from `/companies/{id}/vendors` |
| `VendorApproval` | `/vendors/approval` | super_admin, company_admin | **Tabs: Pending/Approved/Rejected/Suspended/(Not Requested for SA)/All** with counts |
| `VendorCompanyAccess` | `/vendors/company-access` | vendor_admin, vendor_operator | Request access to companies, track status |
| `VendorProfile` | `/profile` | vendor_admin, vendor_operator | Vendor org details |
| `WorkerList` | `/workers` | all | **All/Current/Previous tabs**; row click → WorkerDetail; ID Document column with download |
| `WorkerDetail` | `/workers/:id` | all | Analytics: stats, monthly breakdown, deployment history (vendor only), recent attendance. **Company users: fixed to their company. Vendor users: company dropdown** |
| `WorkerRegister` | `/workers/register` `/workers/:id/edit` | super_admin, vendor_admin, vendor_operator | 4-step wizard. **Edit mode (Step 0): shows existing doc download links** |
| `WorkerAssign` | `/workers/assign` | super_admin, vendor_admin | **Current/Previous/All tabs**; cancel allowed even when locked (if worker OUT) |
| `UserList` | `/users` | super_admin, company_admin | Role-scoped user management |
| `AttendanceMark` | `/attendance/mark` | super_admin, company_admin, company_gate | Fingerprint IN/OUT via SGIBIOSRV |
| `AttendanceList` | `/attendance` | all | **Daily summary grouped view** (not raw IN/OUT). All/Current/Previous tabs. Row click → WorkerDetail |
| `AttendanceExceptions` | `/attendance/exceptions` | all | Workers currently inside (IN without OUT) |
| `FingerprintTest` | `/diagnostic/fingerprint` | super_admin, company_admin, vendor_admin | Scanner diagnostics |
| `Signup` | `/signup` | PUBLIC | SaaS signup wizard: org type → details → plan cards; auto-login |
| `Downloads` | `/downloads` | all | Apps + docs; public twin at /download.html (static) |
| `PlanBilling` | `/billing` | company_admin, vendor_admin | Current plan, usage meters, upgrade request |
| `Subscriptions` | `/subscriptions` | super_admin | All orgs' plans/usage; approve/reject requests; set plan |

---

## Backend Controllers

| Controller | Key methods | Notes |
|------------|------------|-------|
| `AuthController` | `login`, `logout`, `me` | Sanctum token auth |
| `CompanyController` | CRUD, `approveVendor`, `rejectVendor`, `suspendVendor`, `companyVendors` | Role-gated |
| `VendorController` | CRUD, `requestCompany`, `availableCompanies` | |
| `WorkerController` | CRUD, `activate`, `deactivate`, `enrollFingerprint`, `stats`, `servePhoto` | `stats()` scoped by company; `servePhoto()` named route `worker.photo` |
| `WorkerAssignmentController` | `index` (deployment filter), `store`, `destroy` | Cancel checks latest log type before allowing on locked assignments |
| `WorkerIdDocumentController` | `index`, `store`, `download`, `destroy` | Accepts images + PDF; company users allowed if worker associated |
| `AadhaarController` | `extract`, `upload`, `download` | Company users allowed to download if worker attended their company |
| `AttendanceController` | `mark`, `workerTemplates`, `index` (deployment filter), `dailySummary`, `exceptions`, `report` | `dailySummary()` groups by worker+company+date |
| `UserController` | CRUD (role-scoped) | Plan limits enforced on every create branch |
| `SignupController` | `store` | PUBLIC; creates org + admin user on Trial; vendor contact_email pre-check |
| `PlanController` | `show`, `requestUpgrade`, `index`, `setPlan`, `decide` | Org billing + super admin subscriptions |
| `SyncController` | `pull`, `push` | Client-app offline sync; idempotent by client_uuid; plan limits on push |
| `DashboardController` | `stats`, `today`, `activity` | |

---

## API Routes (key ones)

```
POST /api/auth/login
GET  /api/auth/me

GET  /api/companies
GET  /api/companies/{id}/vendors                  ← includes pivot status
POST /api/companies/{id}/vendors/{vid}/approve
POST /api/companies/{id}/vendors/{vid}/reject      ← requires {reason}
POST /api/companies/{id}/vendors/{vid}/suspend

GET  /api/vendors                                  ← ?search=
POST /api/vendors/{id}/request-company/{cid}
GET  /api/vendors/{id}/available-companies

GET  /api/workers                                  ← ?search= ?status= ?deployment=current|previous ?page=
GET  /api/workers/{id}
GET  /api/workers/{id}/stats                       ← ?company_id= (vendor users can filter)
GET  /api/workers/{id}/photo                       ← named: worker.photo
POST /api/workers/{id}/activate
POST /api/workers/{id}/deactivate
POST /api/workers/{id}/fingerprint

GET  /api/workers/{id}/id-documents
POST /api/workers/{id}/id-documents               ← accepts image + PDF (max 10MB)
GET  /api/workers/{id}/id-documents/{doc}/download

POST /api/aadhaar/extract                          ← no storage, extracts data only
POST /api/aadhaar/upload/{worker}                  ← stores PDF on private disk
GET  /api/aadhaar/download/{worker}                ← role-scoped download

GET  /api/attendance                               ← ?deployment=current|previous ?date= ?search= ?page=
GET  /api/attendance/daily-summary                 ← grouped: one row per worker per day
GET  /api/attendance/worker-templates              ← for fingerprint matching at gate
POST /api/attendance/mark
GET  /api/attendance/exceptions

GET  /api/assignments                              ← ?deployment=current|previous (apiResource)
POST /api/assignments                              ← deploy worker {worker_id, company_id, start_date, end_date}
DELETE /api/assignments/{id}
  (NOTE: frontend page is /workers/assign but the API resource is /assignments)

GET  /api/sync/pull                                ← client app: role-scoped bundle (workers/assignments/attendance)
POST /api/sync/push                                ← client app: idempotent batch {device_id, registrations[], marks[]} by client UUID

GET  /api/users
POST /api/users
PUT  /api/users/{id}
```

---

## Key Frontend Patterns

**Private file downloads (blob):**
```js
const r = await api.get(url, { responseType: 'blob' });
const blob = URL.createObjectURL(r.data);
const a = document.createElement('a');
a.href = blob; a.download = filename;
document.body.appendChild(a); a.click();
URL.revokeObjectURL(blob);
```

**Deployment tabs (All / Current / Previous):**
```js
const deploymentParam = tab !== "all" ? tab : undefined;
api.get('/workers', { params: { deployment: deploymentParam } })
```

**Role checks in React:**
```js
const isSuperAdmin  = user?.role === "super_admin";
const isCompanyUser = ["company_admin", "company_gate"].includes(user?.role);
const isVendorUser  = ["vendor_admin", "vendor_operator"].includes(user?.role);
const canRegister   = ["super_admin", "vendor_admin", "vendor_operator"].includes(user?.role);
const canActivate   = ["super_admin", "company_admin", "vendor_admin"].includes(user?.role);
```

**Row click → worker detail (with stopPropagation on action cells):**
```jsx
<tr onClick={() => navigate(`/workers/${w.id}`)} className="cursor-pointer">
  <td onClick={(e) => e.stopPropagation()}>  {/* actions cell */}
```

**Company dropdown for vendor users in WorkerDetail:**
- Options populated once on first unfiltered load via `useEffect` with `companyOptions === null` guard
- Passes `?company_id=X` to `/workers/:id/stats` when selected

---

## Aadhaar Flow

1. Vendor clicks "Open UIDAI Portal" → worker downloads masked Aadhaar PDF
2. `POST /api/aadhaar/extract` → Python pdf-service extracts name/DOB/gender/address/photo (no storage)
3. Form auto-fills; vendor reviews
4. `POST /api/aadhaar/upload/{worker}` → PDF stored at `private/aadhaar/aadhaar_{id}_{ts}.pdf`
5. `workers.aadhaar_pdf_path` updated; `has_aadhaar_pdf` accessor returns `true`
6. `GET /api/aadhaar/download/{worker}` → serves PDF; company users allowed if worker associated

**PDF password:** first 4 letters of name (UPPERCASE) + birth year → `NARE1955`

---

## Fingerprint Flow (Gate)

```
1. Browser → POST https://localhost:8443/SGIFPCapture   (capture live template)
2. Browser → GET  /api/attendance/worker-templates      (all enrolled, decrypted templates for approved vendors)
3. Browser → POST https://localhost:8443/SGIMatchScore  (1:N parallel match, score 0–200)
4. Best score ≥ 40 → confirm dialog → POST /api/attendance/mark
```
- `Content-Type: text/plain` on SGIBIOSRV calls avoids CORS preflight
- Templates decrypted server-side before sending to browser

---

## Common Gotchas

| Issue | Cause | Fix |
|-------|-------|-----|
| Backend change not taking effect | No volume mount on backend — saving file does nothing | `docker cp` file + `php artisan optimize:clear` |
| `Disk [private] does not have a configured driver` | Dockerfile cherry-picked config files; scaffold's filesystems.php lacks the private disk | Dockerfile now does `COPY config/ config/` — keep it that way |
| `Route [worker.photo] not defined` | Named route missing | Route must have `->name('worker.photo')` in api.php |
| `id_documents` vs `idDocuments` | Laravel serialises relationships as snake_case JSON | Use `existingWorker?.id_documents` (not `idDocuments`) in React |
| `aadhaar_pdf_path` always null/empty | Field is in `$hidden` | Check `has_aadhaar_pdf` (in `$appends`) instead |
| Aadhaar vs other docs | Two separate systems | Aadhaar: `workers.aadhaar_pdf_path` via `AadhaarController`; others: `worker_id_documents.document_path` via `WorkerIdDocumentController` |
| `COUNT(DISTINCT alias)` MySQL error | MySQL can't count by alias in same SELECT | Use `selectRaw('COUNT(DISTINCT DATE(marked_at)) as cnt')->value('cnt')` |
| PDF rejected on ID doc upload | Old validation used `image` rule | Validation: `'max:10240\|mimes:jpeg,png,jpg,pdf'` |
| Static route shadowed by dynamic | `/workers/register` vs `/workers/:id` | In App.jsx: put static routes before `/:id` |
| Cancel blocked on locked assignment | Old code blocked all cancels when `is_locked=true` | Now checks latest log — cancel allowed if type is OUT |

---

## CSS Utility Classes (Tailwind custom)

```
.card        — white rounded shadow card
.btn-primary — brand blue button
.btn-secondary — gray outline button
.btn-danger  — red button
.input       — standard form input
.label       — form label
.badge       — status badge wrapper
.badge-green, .badge-yellow, .badge-gray, .badge-red — colored badges
```

---

## Feature Status (as of 2026-08-15 — v1.0.0)

### Implemented & Working
- [x] Multi-company, multi-vendor architecture with pivot approval flow
- [x] Aadhaar PDF upload → Python extraction → auto-fill form
- [x] Fingerprint enrollment via SecuGen SGIBIOSRV
- [x] 1:N fingerprint matching for attendance (parallel, score threshold 40/200)
- [x] Worker registration 4-step wizard (Aadhaar → Details → Fingerprint → Confirm)
- [x] Worker edit mode shows existing documents with download links
- [x] Worker status: pending (no FP) → active (FP enrolled) → inactive/blocked
- [x] Worker deployment (WorkerAssign) with date ranges and Current/Previous/All tabs
- [x] Deployment cancel: allowed even when locked, blocked only if worker currently IN
- [x] Worker list: All/Current/Previous tabs + clickable rows + ID document download column
- [x] Worker Detail analytics page (scoped: company sees own data, vendor uses company dropdown)
- [x] Monthly attendance breakdown with missed OUT count
- [x] Attendance daily summary view (grouped per worker per day) with All/Current/Previous tabs
- [x] Attendance exceptions (workers currently inside)
- [x] Private file storage for Aadhaar PDFs + ID documents (both images and PDFs)
- [x] Company users can download Aadhaar/docs for workers associated with their company
- [x] Vendor Approvals with status tabs + approve/reject (with reason)/suspend/re-approve
- [x] Vendor list: company users see approval-status tabs (Approved/Pending/Suspended/Rejected/All)
- [x] Role-scoped data: each role sees only their relevant data
- [x] Full audit logging for sensitive actions
- [x] **Aadhaar MANDATORY at registration** — PDF extract (masked + HMAC hash) or manual 12-digit entry; raw number never persisted
- [x] **Aadhaar dedup across vendors** — unique `workers.aadhaar_hash` (HMAC-SHA256, keyed by APP_KEY); duplicate → 422
- [x] Login rate-limiting (throttle 5/min per IP on /auth/login)
- [x] Worker `$fillable` hardened — system/biometric fields writable only via forceFill()
- [x] Downloads page (`/downloads`, all roles) — docs served from frontend/public/docs; app builds land here later
- [x] Flutter client app design agreed → see `CLIENT_APP_DESIGN.md`
- [x] **Flutter app v0.9.0-preview BUILT** (`client/`) — login, role modes (vendor/gate/admin-view), offline SQLite store, idempotent sync (pull/push by client UUID), SIM fingerprint + SGIBIOSRV capture on Windows. APK at `frontend/public/downloads/` (NOT in git — build via `flutter build apk`; Windows: `flutter build windows` on a Windows machine)
- [x] Server sync API: `GET /api/sync/pull`, `POST /api/sync/push` (`SyncController`), `client_uuid` unique columns on workers + attendance_logs
- [x] **Company admins create their own vendors** (auto-approved for their company) + can create that vendor's `vendor_admin` login (only for approved vendors)
- [x] **SaaS self-service**: public `/signup` (company OR vendor, minimal fields, GST/PAN optional), plans Trial(3u/10w/3links)/Professional(25/500/25)/Enterprise(100/5000/100) in `config/plans.php`, enforced server-side by `PlanService` at user/worker/link creation (+ sync push). Org admins: `/billing` (usage meters + upgrade request). Super admin: `/subscriptions` (approve/reject requests, set any plan). Payment OFFLINE — **user decision: offline payment stays a permanent option even after a payment gateway (e.g. Razorpay) is added later.** Existing orgs grandfathered to enterprise. Company-created vendors inherit the company's plan.
- [x] **Face attendance (web, v1.1.4)** — camera-only: auto-enroll from registration photo (`FaceService`, pdf-service `/face/embed`, ArcFace 512-D), gate **Face** tab does 1:N `POST /attendance/identify-face`, marks re-verified server-side (`method=face`, `face_score`); threshold `FACE_MATCH_THRESHOLD` (0.45). First face call per pdf-service worker lazy-loads the model (~7s local, ~30-60s droplet)
- [ ] Payroll/salary — explicitly deferred until attendance ships properly (user decision 2026-08-16)
- [x] AuditService used in every write operation

### Not Yet Implemented / Future
- [ ] Self-service password reset
- [ ] Email notifications (vendor approved, worker registered, etc.)
- [ ] Export attendance to Excel/PDF
- [ ] Face recognition (tables exist: `face_descriptor`, `face_enrolled_at`)
- [ ] S3 storage (currently local private disk)
- [ ] Laravel Horizon for queue monitoring UI
- [ ] Mobile app / PWA for gate users

---

## Development Workflow

### Adding a new backend endpoint
1. Edit `backend/app/Http/Controllers/SomeController.php`
2. Register in `backend/routes/api.php`
3. `docker cp` both files into `ams_backend`
4. `docker exec ams_backend php artisan optimize:clear`
5. Test via React frontend

### Adding a new frontend page
1. Create `frontend/src/pages/.../NewPage.jsx`
2. Import and add route in `frontend/src/App.jsx`
3. Add nav item to `frontend/src/components/layout/Sidebar.jsx` (NAV array) if needed
4. Changes are live immediately (Vite HMR)

### Making DB schema changes
```bash
docker exec ams_backend php artisan make:migration add_column_to_table
# Edit migration file, then:
docker exec ams_backend php artisan migrate
```

---

## Documentation Files

| File | Purpose |
|------|---------|
| `CLAUDE.md` | This file — project context for Claude sessions |
| `README.md` | Technical overview, architecture, API reference |
| `USER_MANUAL.md` | User-facing guide for all roles |
| `docs/developer-guide.html` | Full technical reference (styled HTML) |
| `docs/user-manual.html` | Full user guide (styled HTML) |

**Keep all five in sync when features change.**
