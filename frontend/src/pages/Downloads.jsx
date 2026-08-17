import { Download, Smartphone, Monitor, BookOpen, FileCode2, Clock } from "lucide-react";

/**
 * Downloads — app installers + documentation.
 * App builds appear here as they are released (see CLIENT_APP_DESIGN.md);
 * until then they are listed as "in development" so users know what's coming.
 */

const APPS = [
  {
    icon: Smartphone,
    title: "Android App",
    desc: "Full worker registration (Aadhaar + fingerprint + face) and gate attendance, offline-capable. SecuGen SDK bundled — plug a USB scanner in and scan; camera face match on-device.",
    status: "/downloads/truecrew-android-v0.9.20-preview.apk",
  },
  {
    icon: Monitor,
    title: "Windows App",
    desc: "Registration + attendance station. Talks to SecuGen scanners directly via the FDx SDK — no extra service needed.",
    status: "/downloads/truecrew-windows-x64-v0.9.20-preview.zip",
  },
];

const DOCS = [
  {
    icon: BookOpen,
    title: "Client Guide",
    desc: "Start here — features, the end-to-end flow, and how-tos for every role. Shareable with client teams.",
    href: "/docs/client-guide.html",
  },
  {
    icon: BookOpen,
    title: "User Manual",
    desc: "Step-by-step guide for every role — super admin, company, gate and vendor users.",
    href: "/docs/user-manual.html",
  },
  {
    icon: BookOpen,
    title: "Release Notes",
    desc: "What changed in every version — apps and web platform.",
    href: "/release-notes.html",
  },
  {
    icon: FileCode2,
    title: "Developer Guide",
    desc: "Technical reference — architecture, API, database schema and deployment.",
    href: "/docs/developer-guide.html",
  },
];

export default function Downloads() {
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Downloads</h1>
          <p className="text-sm text-gray-500 mt-1">
            Apps and documentation for the Attendance Management System.
          </p>
        </div>
        {/* Public page — same content, no login needed; safe to share with clients */}
        <a href="/download.html" target="_blank" rel="noreferrer" className="btn-secondary">
          <Download size={15} /> Public download page
        </a>
      </div>

      {/* ── Apps ─────────────────────────────────────────────────────────── */}
      <div>
        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
          Apps
        </h2>
        <div className="grid gap-4 sm:grid-cols-2">
          {APPS.map(({ icon: Icon, title, desc, status }) => (
            <div key={title} className="card p-5 flex flex-col">
              <div className="flex items-center gap-3 mb-2">
                <div className="p-2 rounded-lg bg-brand-50 text-brand-600">
                  <Icon size={22} />
                </div>
                <h3 className="font-semibold text-gray-900">{title}</h3>
              </div>
              <p className="text-sm text-gray-500 flex-1">{desc}</p>
              <div className="mt-4">
                {status === "in_development" ? (
                  <span className="badge badge-yellow inline-flex items-center gap-1.5">
                    <Clock size={13} /> In development
                  </span>
                ) : (
                  <a href={status} className="btn-primary inline-flex" download>
                    <Download size={15} /> Download
                  </a>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* ── Documentation ────────────────────────────────────────────────── */}
      <div>
        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
          Documentation
        </h2>
        <div className="grid gap-4 sm:grid-cols-2">
          {DOCS.map(({ icon: Icon, title, desc, href }) => (
            <div key={title} className="card p-5 flex flex-col">
              <div className="flex items-center gap-3 mb-2">
                <div className="p-2 rounded-lg bg-gray-100 text-gray-600">
                  <Icon size={22} />
                </div>
                <h3 className="font-semibold text-gray-900">{title}</h3>
              </div>
              <p className="text-sm text-gray-500 flex-1">{desc}</p>
              <div className="mt-4">
                <a href={href} target="_blank" rel="noreferrer" className="btn-secondary inline-flex">
                  <BookOpen size={15} /> Open
                </a>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
