# TrueCrew User Guides

> **Reorganised 2026-08-17 (Platform v1.7.0, apps v0.9.28):** the single combined
> manual was split into role-specific guides. Each guide is complete for its role
> and kept current with every release — this file is now just the map.

| Guide | Audience | Where |
|-------|----------|-------|
| **Super Admin Guide** | Platform owner — organisations, subscriptions & plans, users, notification templates, oversight & digests | `frontend/public/docs/super-admin-guide.html` → served at `/docs/super-admin-guide.html` |
| **Company Guide** | Company admins, HR, and gate teams — vendor approvals, deployment approvals per gate/department, attendance, manual OUT, reports | `frontend/public/docs/company-guide.html` → `/docs/company-guide.html` |
| **Vendor Guide** | Vendor admins & operators — Aadhaar + fingerprint registration, deployments, the app-first workflow (offline, notifications, worker actions) | `frontend/public/docs/vendor-guide.html` → `/docs/vendor-guide.html` |
| **Client Guide** | Prospects / client teams — one-page product overview | `frontend/public/docs/client-guide.html` → `/docs/client-guide.html` |
| **Developer Guide** | Super admin's technical companion — architecture, schema, deploy loop, debugging | `frontend/public/docs/developer-guide.html` → `/docs/developer-guide.html` |

All five are linked from the **Downloads** page in the portal and the public
`/download.html` page. `/docs/user-manual.html` is a chooser page that points to
the guides above, so old bookmarks keep working.

## Rules worth remembering (enforced by the server)

- **Aadhaar is mandatory** at registration (PDF extract or manual 12-digit);
  the raw number is never stored — only a masked copy and a keyed hash, which
  also blocks duplicates across vendors.
- **Engagement lock (v1.6.1):** once a worker is deployed to a company — or is
  still checked IN — the vendor cannot delete, deactivate, or remove the
  fingerprint. Cancel the deployment first; the company marks them OUT.
  Editing details stays allowed.
- **Plan fairness:** only workers who actually worked (deployed + first IN)
  count against the plan's worker limit.
- **Deployment approvals:** a company can require HR approval per deployment
  and restrict workers to named gates/departments (Main Gate is the default).
