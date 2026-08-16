> **What's new (v1.1, 2026-08-16):** AMS is now self-service — new organisations
> sign up at `/signup` (Company or Vendor, free Trial). Org admins manage their
> plan on **Plan & Billing**; the platform owner manages everything on
> **Subscriptions**. Company admins can now CREATE their own vendors. Apps for
> Android/Windows: see the **Downloads** page. The freshest end-user guide is
> `/docs/client-guide.html` (also linked on Downloads).

# AMS User Manual
## Workforce Attendance Management System

**Version:** 2.0
**Audience:** Company Admins, Vendor Admins, Gate Users

---

## Table of Contents

1. [What is AMS?](#1-what-is-ams)
2. [Who Uses AMS? (Roles Explained)](#2-who-uses-ams-roles-explained)
3. [How the System Works — Big Picture](#3-how-the-system-works--big-picture)
4. [Getting Started — Logging In](#4-getting-started--logging-in)
5. [Super Admin Guide](#5-super-admin-guide)
6. [Company Admin Guide](#6-company-admin-guide)
7. [Vendor Admin Guide](#7-vendor-admin-guide)
8. [Gate User Guide](#8-gate-user-guide)
9. [Onboarding Checklist](#9-onboarding-checklist)
10. [Frequently Asked Questions](#10-frequently-asked-questions)

---

## 1. What is AMS?

AMS (Attendance Management System) is a digital system that tracks **when contract workers arrive and leave** a company site — using **fingerprint scanning**, so there is no manual register or paper-based attendance.

### What problems it solves

| Old Way | With AMS |
|---------|----------|
| Paper register — can be faked | Fingerprint — cannot be faked |
| Manual data entry — errors | Auto-recorded with timestamp |
| No visibility for vendor | Vendor sees their workers' attendance live |
| Hard to audit | Full audit trail with every action logged |

---

## 2. Who Uses AMS? (Roles Explained)

AMS has four types of users. Each person sees only what is relevant to their job.

```
┌─────────────────────────────────────────────────────────────┐
│                        SUPER ADMIN                          │
│  (System owner — sets up everything once)                   │
│  Creates companies, vendors, and their login accounts        │
└───────────────────┬─────────────────────┬───────────────────┘
                    │                     │
          ┌─────────▼──────┐   ┌──────────▼─────────┐
          │  COMPANY ADMIN │   │   VENDOR ADMIN      │
          │                │   │                     │
          │ Approves which │   │ Registers workers   │
          │ vendors work   │   │ (Aadhaar + finger-  │
          │ at their site  │   │  print enrollment)  │
          │                │   │                     │
          │ Creates gate   │   │ Deploys workers     │
          │ users          │   │ to companies        │
          └───────┬────────┘   └─────────────────────┘
                  │
          ┌───────▼────────┐
          │   GATE USER    │
          │                │
          │ Scans finger-  │
          │ prints at site │
          │ entry/exit     │
          └────────────────┘
```

### Quick Reference

| Role | Who is this? | What they do |
|------|-------------|--------------|
| **Super Admin** | System owner / IT team | Creates companies and vendors, manages all accounts |
| **Company Admin** | HR Manager / Site Manager at the company | Approves vendors, creates gate users, views attendance |
| **Vendor Admin** | Contractor company manager | Registers workers, deploys to companies, views worker attendance |
| **Gate User** | Security guard / HR at the gate | Scans fingerprints to mark workers IN and OUT |

---

## 3. How the System Works — Big Picture

```
STEP 1: Super Admin creates Company & Vendor accounts
         ↓
STEP 2: Vendor Admin requests access to work at a Company
         ↓
STEP 3: Company Admin approves the Vendor
         ↓
STEP 4: Vendor Admin registers Workers
         (Aadhaar PDF upload + Fingerprint scan)
         ↓
STEP 5: Vendor Admin deploys Workers to a Company (with date range)
         ↓
STEP 6: Company Admin creates Gate User accounts
         ↓
STEP 7: Gate User scans fingerprints → Attendance marked automatically
         ↓
STEP 8: Vendor Admin & Company Admin view attendance reports
         Click any worker row → full Worker Detail analytics page
```

---

## 4. Getting Started — Logging In

### Step 1: Open the Application

Open your web browser (Chrome or Edge recommended) and go to:
```
http://[your-server-address]
```
Your IT team will provide the exact URL.

### Step 2: Login Screen

Enter your **email address** and **password** provided by the Super Admin.

### Step 3: Dashboard

After logging in, you will see the **Dashboard** with a summary of today's attendance and key numbers. The left sidebar shows the menu options available to your role.

> **Tip:** If you forget your password, contact your Super Admin. There is no self-service password reset.

---

## 5. Super Admin Guide

The Super Admin sets up the system for everyone. This is typically done once during initial setup.

### 5.1 Create a Company

**Where:** Sidebar → Administration → **Companies**

1. Click **Add Company**
2. Fill in the company details (Name, Code, Address, Contact, GST, etc.)
3. Fill in **Company Admin Login** — click the refresh icon to auto-generate a password, copy it
4. Click **Create Company** and share the credentials with the Company Admin

> **Copy the password before saving — it will not be shown again.**

---

### 5.2 Create a Vendor

**Where:** Sidebar → Administration → **Vendors**

1. Click **Add Vendor**
2. Fill in vendor details and **Vendor Admin Login** (same process as Company)
3. Click **Create Vendor** and share credentials with the Vendor Admin

---

### 5.3 Manage All Users

**Where:** Sidebar → Administration → **Users**

Create any user type (Company Admin, Gate User, Vendor Admin, Vendor Operator). Select the role, then assign to the correct Company or Vendor.

---

### 5.4 Edit or Deactivate a Company / Vendor

Each card has a **⋮ (three-dot) menu** in the top-right corner:
- **Edit** — update details
- **Deactivate / Activate** — disable or enable the entire account

---

## 6. Company Admin Guide

The Company Admin is responsible for approving vendors, creating gate users, and viewing attendance at their site.

### 6.1 Approve a Vendor

When a vendor requests access to work at your company, their request appears in **Vendor Approvals**.

**Where:** Sidebar → Administration → **Vendor Approvals**

The page shows tabs with counts: **Pending** | **Approved** | **Rejected** | **Suspended** | **All**

**To approve:**
1. Click the **Pending** tab
2. Click **Approve** next to the vendor name

**Once approved:** The vendor's workers are automatically available for fingerprint attendance — no further steps needed.

**To reject:** Click **Reject** and enter a reason. The vendor can see the reason and re-request after fixing the issue.

**To suspend (temporarily block):** Click the **Approved** tab, then click **Suspend** next to the vendor. Re-approve later when ready.

---

### 6.2 View Your Vendors

**Where:** Sidebar → Administration → **Vendors**

Company users see vendors filtered by their approval status with tabs:

| Tab | What it shows |
|-----|--------------|
| **Approved** | Vendors with active access — workers can attend |
| **Pending** | Vendors awaiting your approval |
| **Suspended** | Vendors temporarily blocked |
| **Rejected** | Vendors you have declined |
| **All** | Every vendor in your relationship list |

Each vendor card shows both the vendor's own status (active/inactive) and their approval status for your company.

---

### 6.3 Create Gate Users

**Where:** Sidebar → Administration → **Users**

1. Click **Add Gate User** (or **Add User** → select role: Gate User)
2. Enter Name, Email, and auto-generate a password (copy before saving)
3. Click **Create**

> **One account per gate:** Create a separate gate user account for each entry/exit point (Main Gate, Back Gate, etc.) so attendance records show exactly which gate was used.

---

### 6.4 View Workers

**Where:** Sidebar → Workers → **All Workers**

The Workers list shows all workers deployed at your company, with three tabs:

| Tab | What it shows |
|-----|--------------|
| **All Workers** | Every worker ever associated with your company |
| **Current** | Workers currently deployed and active at your site |
| **Previous** | Workers who previously attended but are no longer deployed |

**Click any worker row** to open their **Worker Detail page** showing:
- Total days worked, average hours, total IN/OUT counts
- Monthly breakdown table with missed OUT count
- Recent attendance log (specific to your company)

The worker list also shows an **ID Document** column — click **Download** to get the worker's Aadhaar PDF or other ID document.

---

### 6.5 View Attendance

**Where:** Sidebar → Attendance → **Attendance Log**

The attendance log shows a **daily summary** — one row per worker per day:

| Column | What it shows |
|--------|--------------|
| Worker | Name and vendor |
| Company | Which company (your company) |
| Location | Gate/location where attendance was marked |
| First IN | Earliest IN time for the day (green) |
| Last OUT | Latest OUT time for the day (blue) |
| Duration | Total time from first IN to last OUT |
| Status | Inside (still in) / Done (checked out) / Incomplete (no OUT recorded) |

Use the **All / Current Workers / Previous Workers** tabs and date/name filters to narrow results.

**Click any row** to open that worker's full detail analytics page.

**Where:** Sidebar → Attendance → **Exceptions**

Shows workers who are currently **inside (IN but no OUT)** — useful for end-of-day checks.

---

## 7. Vendor Admin Guide

The Vendor Admin is responsible for:
- Requesting access to company sites
- Registering workers with their Aadhaar and fingerprint
- Deploying workers to specific companies
- Viewing attendance reports for their workers

### 7.1 Request Access to a Company

Before your workers can mark attendance at a company, you must request access.

**Where:** Sidebar → Administration → **Company Access**

1. Find the company you want to work with
2. Click **Request Access**
3. Wait for the company admin to approve

**Request statuses:**

| Status | Meaning | What to do |
|--------|---------|------------|
| No status | Not yet requested | Click Request Access |
| Pending | Request sent, waiting | Wait for company admin |
| Approved | Access granted | Workers can now attend |
| Rejected | Rejected with reason | Read reason, fix issue, re-request |
| Suspended | Temporarily blocked | Contact the company |

---

### 7.2 Register a Worker

Workers must be registered before they can use the fingerprint scanner.

**Where:** Sidebar → Workers → **Register Worker**

#### Step 1 — Aadhaar Details

1. Click **Open UIDAI Portal** — the worker logs in and downloads their masked Aadhaar PDF
2. Upload the PDF. The PDF password is: **first 4 letters of name (UPPERCASE) + birth year**
   - Example: Name "Narendra", Born 1955 → password `NARE1955`
3. The form auto-fills with the worker's details from the PDF

#### Step 2 — Review Worker Details

Check the auto-filled information (Name, DOB, Gender, Address). Make any corrections. Click **Next**.

#### Step 3 — Fingerprint Enrollment

1. Connect the SecuGen fingerprint scanner to the PC
2. Ask the worker to place their **right index finger** on the scanner
3. Click **Scan Fingerprint** — keep the finger pressed for 2 seconds
4. Green checkmark = success

> **Scanner not available?** Click **Skip for now**. The worker is saved as "Pending". Enroll fingerprint later from the Workers list. Workers cannot mark attendance until fingerprint is enrolled.

#### Step 4 — Confirm & Save

Review all details and click **Register Worker**.

---

### 7.3 Edit a Worker

**Where:** Workers list → click worker row → click **Edit**, or Workers list → **Edit** link

In edit mode (Step 0), you can see the worker's existing documents:
- If Aadhaar PDF was uploaded → **Download PDF** link is shown
- If other ID document was uploaded → **Download** link is shown

Upload new Aadhaar or ID documents to replace existing ones.

---

### 7.4 View Your Workers

**Where:** Sidebar → Workers → **All Workers**

The Workers list has three tabs:

| Tab | What it shows |
|-----|--------------|
| **All Workers** | All workers registered under your vendor |
| **Current** | Workers with active deployments at a company today |
| **Previous** | Workers who have attended but are no longer actively deployed |

The list also shows:
- Fingerprint enrollment status (green fingerprint icon = enrolled)
- Aadhaar number (masked)
- ID Document with download link
- Status badge (Pending / Active / Inactive)

**Click any worker row** to open the **Worker Detail analytics page**.

---

### 7.5 Worker Detail Analytics

**Where:** Click any worker row in the Workers list

The detail page shows:
- **Company dropdown** at the top — switch between companies to see that worker's history at each approved company
- **Stats cards:** Total days, average daily hours, total IN/OUT count
- **Monthly breakdown table:** Days worked, average hours, missed OUT count per month
- **Deployment history:** All deployments for that worker at the selected company
- **Recent attendance:** Last 10 attendance events

---

### 7.6 Deploy Workers to a Company

**Where:** Sidebar → Workers → **Deploy Workers**

After a company approves your vendor, you can deploy specific workers with a date range.

The deployment list has three tabs:

| Tab | What it shows |
|-----|--------------|
| **Current** | Active deployments within today's date range |
| **Previous** | Expired or cancelled deployments |
| **All** | All deployments |

**To cancel a deployment:** Click **Cancel** next to any deployment. You can cancel even after attendance has been marked, **as long as the worker is not currently checked IN**.

---

### 7.7 View Attendance

**Where:** Sidebar → Attendance → **Attendance Log**

Shows a **daily summary** for all your workers across all companies:
- One row per worker per day
- First IN time, Last OUT time, total duration
- Status: Inside / Done / Incomplete

Use the **All / Current / Previous** tabs and date/name filters to narrow results. Click any row to open the worker's full detail page.

---

## 8. Gate User Guide

The Gate User's job is simple: **scan fingerprints** as workers enter and exit.

### 8.1 Setup — First Time Only (Per PC)

1. Make sure the **SecuGen fingerprint scanner** is plugged into the USB port
2. Open the browser and go to `https://localhost:8443`
3. You will see a security warning — click **Advanced** → **Proceed to localhost**

> This is a one-time step per PC that allows the browser to talk to the fingerprint scanner.

---

### 8.2 Mark Attendance — Daily Use

**Where:** Sidebar → Attendance → **Mark Attendance**

**Attendance flow for each worker:**

1. Click **Scan Fingerprint**
2. The circle starts pulsing — tell the worker to place their finger
3. Worker places finger on scanner firmly
4. System identifies the worker and shows their name + pending action (IN or OUT)
5. Click **Confirm IN** or **Confirm OUT**
6. Done! Repeat for the next worker

> **IN vs OUT is automatic:** The system tracks whether the worker's last action was IN or OUT. If they last marked IN, the next scan shows "Pending: OUT".

---

### 8.3 Troubleshooting Fingerprint Issues

| Problem | What to do |
|---------|-----------|
| "No finger detected" | Worker must place finger **immediately** after clicking Scan — don't wait |
| "No fingerprint match found" | Worker's finger may be dirty/wet — clean and try again. If still fails, contact vendor admin to check enrollment |
| "Cannot reach scanner" | Check USB connection. Open `https://localhost:8443` and accept certificate |
| Scanner light doesn't blink | Scanner not connected — check USB. Try a different USB port |
| "No workers found" | Vendor may not be approved for your company — contact company admin |

---

## 9. Onboarding Checklist

### For Super Admin — New Company Setup

- [ ] Create Company (Administration → Companies → Add Company)
- [ ] Generate and copy Company Admin password
- [ ] Share login credentials with Company Admin
- [ ] Confirm Company Admin can log in

### For Super Admin — New Vendor Setup

- [ ] Create Vendor (Administration → Vendors → Add Vendor)
- [ ] Generate and copy Vendor Admin password
- [ ] Share login credentials with Vendor Admin
- [ ] Confirm Vendor Admin can log in

### For Vendor Admin — Getting Started

- [ ] Log in to AMS
- [ ] Go to **Company Access** → find client company → **Request Access**
- [ ] Wait for company approval
- [ ] Register workers (Aadhaar PDF + fingerprint enrollment)
- [ ] Go to **Deploy Workers** → assign workers to the company with date range
- [ ] Confirm worker status shows **Active**

### For Company Admin — Getting Started

- [ ] Log in to AMS
- [ ] Go to **Vendor Approvals** → **Pending** tab → **Approve** vendors
- [ ] Go to **Users** → **Add Gate User** for each entry point
- [ ] Share gate user credentials with security/HR staff at each gate
- [ ] Confirm gate user can log in and reach the Mark Attendance page

### For Gate User — Gate PC Setup (One Time)

- [ ] Open browser on gate PC → Go to `https://localhost:8443` → Accept certificate
- [ ] Log in to AMS with gate user credentials
- [ ] Go to **Mark Attendance**
- [ ] Test with one known worker fingerprint to confirm scanner is working

---

## 10. Frequently Asked Questions

**Q: A worker's fingerprint is not being recognized. What do I do?**

First, try cleaning the worker's finger and the scanner sensor. Ask them to press harder and keep the finger still. If it still fails, the vendor admin should re-enroll the fingerprint from the Workers list → click the worker → re-enroll.

---

**Q: The gate scanner is not working / shows an error.**

1. Check that the SecuGen USB scanner is properly connected
2. In the browser, go to `https://localhost:8443` — if you see a certificate warning, click Advanced → Proceed
3. Refresh the Mark Attendance page and try again

---

**Q: A vendor's workers are not showing up for attendance marking.**

This means the vendor has not been approved yet. The company admin must go to **Vendor Approvals** and approve the vendor. Once approved, their workers are immediately available.

---

**Q: How do I add more workers under our vendor?**

The Vendor Admin or Vendor Operator can register new workers at any time via **Register Worker**. Once registered and fingerprint enrolled, they are immediately available at all approved companies.

---

**Q: Can a worker be at two companies on the same day?**

Yes. If your vendor is approved at multiple companies, a worker can mark IN/OUT at any of them. Each record shows which company it was marked at.

---

**Q: What if a worker forgot to mark OUT?**

The Company Admin can see these workers in **Attendance → Exceptions**. Manual corrections can be made from there.

---

**Q: How do I view a worker's full attendance history?**

Click any worker's row in the **Workers** list or **Attendance Log** to open the **Worker Detail** analytics page. It shows total days worked, average hours, monthly breakdowns, and recent attendance — all scoped to your company.

---

**Q: Can I download a worker's Aadhaar document?**

Vendor users can download any worker's Aadhaar PDF from the Workers list (ID Document column) or in edit mode (Step 0). Company admin/gate users can also download if the worker has attended or been deployed at their company.

---

**Q: I cancelled a worker deployment but they still appear in the Previous Workers tab. Is that correct?**

Yes. Once a worker has marked attendance at your company, they will always appear in the **Previous Workers** tab — even if the deployment is later cancelled. This ensures a complete history is maintained.

---

**Q: How do I change a gate user's password?**

The Company Admin goes to **Users**, finds the gate user, clicks the edit icon, enters a new password, and saves.

---

**Q: I cannot log in — it says incorrect password.**

Make sure you are using the exact email and password provided by your admin. Passwords are case-sensitive. Contact your Super Admin or Company Admin to reset your password.

---

**Q: The vendor was approved but we want to stop them. What do we do?**

The Company Admin goes to **Vendor Approvals → Approved** tab, then clicks **Suspend** next to the vendor. Their workers' fingerprints will immediately stop working at your gate. You can re-approve them later.

---

*For technical issues with the server or database, contact your IT administrator.*
