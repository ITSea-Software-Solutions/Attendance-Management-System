import { useEffect, useState } from "react";
import toast from "react-hot-toast";
import { MailCheck, RotateCcw, Save } from "lucide-react";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";

/**
 * Notification templates editor.
 *  - super_admin edits the GLOBAL defaults (every org inherits them)
 *  - company/vendor admins edit THEIR ORG's overrides (Professional+;
 *    plan-gated server-side, mirrored here via can_override)
 * Placeholders like {{name}} are substituted at send time.
 */
export default function Templates() {
  const { user } = useAuth();
  const [templates, setTemplates] = useState([]);
  const [scope, setScope] = useState("org");
  const [canOverride, setCanOverride] = useState(true);
  const [open, setOpen] = useState(null); // key of the expanded editor
  const [drafts, setDrafts] = useState({}); // key → {subject, body}
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const r = await api.get("/templates");
    setTemplates(r.data.templates ?? []);
    setScope(r.data.scope);
    setCanOverride(r.data.can_override);
  };
  useEffect(() => { load(); }, []);

  const draftFor = (t) => drafts[t.key] ?? { subject: t.subject ?? "", body: t.body };

  const save = async (t) => {
    const d = draftFor(t);
    setBusy(true);
    try {
      await api.post("/templates", { key: t.key, subject: d.subject || null, body: d.body });
      toast.success("Template saved.");
      setOpen(null);
      setDrafts((x) => ({ ...x, [t.key]: undefined }));
      load();
    } catch (e) {
      toast.error(e.response?.data?.message ?? "Save failed.");
    } finally { setBusy(false); }
  };

  const reset = async (t) => {
    setBusy(true);
    try {
      await api.post("/templates/reset", { key: t.key });
      toast.success("Reset to default.");
      setOpen(null);
      setDrafts((x) => ({ ...x, [t.key]: undefined }));
      load();
    } catch { toast.error("Reset failed."); } finally { setBusy(false); }
  };

  const sourceBadge = (s) =>
    s === "org" ? <span className="badge badge-green">Customized by your org</span>
    : s === "global" ? <span className="badge badge-yellow">{scope === "global" ? "Customized" : "Platform default"}</span>
    : <span className="badge badge-gray">Built-in default</span>;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Notification Templates</h1>
        <p className="text-sm text-gray-500 mt-0.5">
          {scope === "global"
            ? "You are editing the PLATFORM DEFAULTS — every organisation inherits these unless they customise their own."
            : "Customise the messages your organisation sends. Anything you don't change uses the platform default."}
        </p>
      </div>

      {!canOverride && scope === "org" && (
        <div className="card border-amber-300 bg-amber-50 text-sm text-amber-800">
          Custom templates are a <b>Professional / Enterprise</b> feature — you can view the texts below;
          upgrade on the <a href="/billing" className="underline font-medium">Plan &amp; Billing</a> page to edit them.
        </div>
      )}

      <div className="space-y-3">
        {templates.map((t) => {
          const d = draftFor(t);
          const editing = open === t.key;
          return (
            <div key={t.key} className="card">
              <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2">
                  <MailCheck size={16} className="text-brand-600" />
                  <span className="font-medium text-gray-900 text-sm">{t.label}</span>
                  {sourceBadge(t.source)}
                </div>
                <button
                  className="btn-secondary text-xs"
                  onClick={() => setOpen(editing ? null : t.key)}
                >
                  {editing ? "Close" : (canOverride ? "Edit" : "View")}
                </button>
              </div>

              {editing && (
                <div className="mt-4 space-y-3">
                  <div className="text-xs text-gray-500">
                    Placeholders:{" "}
                    {t.vars.map((v) => (
                      <code key={v} className="bg-gray-100 rounded px-1.5 py-0.5 mr-1.5">{`{{${v}}}`}</code>
                    ))}
                  </div>
                  {t.subject !== null && (
                    <div>
                      <label className="label">Subject</label>
                      <input className="input" value={d.subject} disabled={!canOverride}
                        onChange={(e) => setDrafts((x) => ({ ...x, [t.key]: { ...d, subject: e.target.value } }))} />
                    </div>
                  )}
                  <div>
                    <label className="label">Message</label>
                    <textarea className="input min-h-[130px] font-mono text-[13px]" value={d.body} disabled={!canOverride}
                      onChange={(e) => setDrafts((x) => ({ ...x, [t.key]: { ...d, body: e.target.value } }))} />
                  </div>
                  {canOverride && (
                    <div className="flex gap-2">
                      <button className="btn-primary text-sm" disabled={busy} onClick={() => save(t)}>
                        <Save size={14} /> Save
                      </button>
                      {(t.source !== "builtin") && (
                        <button className="btn-secondary text-sm" disabled={busy} onClick={() => reset(t)}>
                          <RotateCcw size={14} /> Reset to default
                        </button>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {user?.role !== "super_admin" && (
        <p className="text-xs text-gray-400">
          Email delivery uses your platform's configured mail provider. WhatsApp notifications (Enterprise)
          activate once the WhatsApp Business API is connected by the platform team.
        </p>
      )}
    </div>
  );
}
