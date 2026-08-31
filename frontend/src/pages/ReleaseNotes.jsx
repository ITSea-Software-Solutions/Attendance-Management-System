import { ExternalLink } from "lucide-react";

/**
 * In-portal release notes — renders the SAME public page (single source of
 * truth, updated every release) inside the portal so admins see what's new
 * without leaving. Same-origin iframe: no duplication, no drift.
 */
export default function ReleaseNotes() {
  return (
    <div className="space-y-4 h-full flex flex-col">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">What's New</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Every release — web platform and the Android/Windows apps.
          </p>
        </div>
        <a href="/release-notes.html" target="_blank" rel="noreferrer" className="btn-secondary">
          <ExternalLink size={15} /> Open full page
        </a>
      </div>
      <iframe
        title="Release notes"
        src="/release-notes.html"
        className="w-full flex-1 min-h-[75vh] rounded-xl border border-gray-200 bg-white"
      />
    </div>
  );
}
