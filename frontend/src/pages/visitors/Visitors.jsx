import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import api from "@/lib/axios";
import AuthImg from "@/components/AuthImg";
import { useAuth } from "@/contexts/AuthContext";
import toast from "react-hot-toast";
import { format } from "date-fns";
import {
  Contact, Users, Plus, Check, X, LogIn, LogOut, Camera, Pencil,
  MessageCircle,
} from "lucide-react";

const STATUS_BADGE = {
  pending:  "badge-yellow",
  approved: "badge-green",
  denied:   "badge-red",
  expired:  "badge-gray",
};

const HOST_INIT = { name: "", phone: "", position: "", department: "" };

/**
 * Visitors — gate passes (today) + the HR-maintained host list.
 * Flow: gate creates a pass → the host is asked on WhatsApp (YES/NO) when
 * configured → entry allowed once approved. Manual decisions always carry a
 * note and land in the audit log.
 */
export default function Visitors() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState("passes");
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [hostForm, setHostForm] = useState(null); // null | {id?, ...fields}

  const isManager = ["super_admin", "company_admin", "company_hr"].includes(user?.role);

  const { data: passes, isLoading: pLoading } = useQuery({
    queryKey: ["gate-passes", date],
    queryFn: () => api.get("/gate-passes", { params: { date } }).then((r) => r.data),
    refetchInterval: 15000,
  });
  const { data: hosts, isLoading: hLoading } = useQuery({
    queryKey: ["visitor-hosts"],
    queryFn: () => api.get("/visitor-hosts").then((r) => r.data),
  });

  const refetchAll = () => {
    queryClient.invalidateQueries(["gate-passes"]);
    queryClient.invalidateQueries(["visitor-hosts"]);
  };

  const decide = useMutation({
    mutationFn: ({ id, decision }) => {
      const note = window.prompt(
        `${decision === "approved" ? "Allow" : "Deny"} this visitor — how did the host respond? (required, audited)`
      );
      if (!note?.trim()) return Promise.reject(new Error("note-required"));
      return api.post(`/gate-passes/${id}/decide`, { decision, note: note.trim() });
    },
    onSuccess: () => { toast.success("Decision recorded."); refetchAll(); },
    onError: (e) =>
      e.message === "note-required"
        ? toast.error("A note is required.")
        : toast.error(e.response?.data?.message ?? "Failed."),
  });

  const move = useMutation({
    mutationFn: ({ id, direction }) => api.post(`/gate-passes/${id}/move`, { direction }),
    onSuccess: (r) => { toast.success(r.data.exit_at ? "Exit recorded." : "Entry recorded."); refetchAll(); },
    onError: (e) => toast.error(e.response?.data?.message ?? "Failed."),
  });

  const saveHost = useMutation({
    mutationFn: (f) =>
      f.id
        ? api.put(`/visitor-hosts/${f.id}`, f)
        : api.post("/visitor-hosts", f),
    onSuccess: () => { toast.success("Host saved."); setHostForm(null); refetchAll(); },
    onError: (e) =>
      toast.error(
        Object.values(e.response?.data?.errors ?? {})[0]?.[0] ??
        e.response?.data?.message ?? "Save failed."
      ),
  });

  const toggleHost = useMutation({
    mutationFn: (h) => api.put(`/visitor-hosts/${h.id}`, { is_active: !h.is_active }),
    onSuccess: () => refetchAll(),
    onError: (e) => toast.error(e.response?.data?.message ?? "Failed."),
  });

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <Contact className="text-brand-600" size={24} /> Visitors
          </h1>
          <p className="text-sm text-gray-500 mt-1">
            Gate passes with host approval — the host is asked on WhatsApp (YES/NO) where enabled;
            phone answers are recorded manually with a note.
          </p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-gray-200">
        {[["passes", "Gate Passes"], ["hosts", "Hosts (who can receive visitors)"]].map(([k, label]) => (
          <button key={k} onClick={() => setTab(k)}
            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${
              tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-gray-500 hover:text-gray-700"
            }`}>
            {label}
          </button>
        ))}
      </div>

      {/* ── Passes ─────────────────────────────────────────────────────── */}
      {tab === "passes" && (
        <div className="space-y-3">
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)}
            className="input w-auto" />
          <div className="card p-0 overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                  <tr>
                    <th className="px-4 py-2">Guest</th>
                    <th className="px-4 py-2">Meets</th>
                    <th className="px-4 py-2">Status</th>
                    <th className="px-4 py-2">In / Out</th>
                    <th className="px-4 py-2">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {pLoading && (
                    <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">Loading…</td></tr>
                  )}
                  {!pLoading && !(passes ?? []).length && (
                    <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">
                      No passes on this date. Gate users create them in the app (Visitors tab).
                    </td></tr>
                  )}
                  {(passes ?? []).map((p) => (
                    <tr key={p.id} className="hover:bg-gray-50/60">
                      <td className="px-4 py-2.5">
                        <div className="flex items-center gap-2.5">
                          <AuthImg
                            url={p.has_photo ? `/gate-passes/${p.id}/photo` : null}
                            alt={p.guest_name}
                            className="w-9 h-9 rounded-lg object-cover border border-gray-200"
                            fallback={
                              <div className="w-9 h-9 rounded-lg bg-gray-50 border border-dashed border-gray-200 flex items-center justify-center">
                                <Camera size={13} className="text-gray-300" />
                              </div>
                            }
                          />
                          <div>
                            <p className="font-medium text-gray-900">{p.guest_name}</p>
                            <p className="text-[11px] text-gray-400">
                              {p.code}{p.guest_phone ? ` · ${p.guest_phone}` : ""}{p.purpose ? ` · ${p.purpose}` : ""}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-2.5 text-gray-700">
                        {p.host?.name}
                        <p className="text-[11px] text-gray-400">{[p.host?.position, p.host?.department].filter(Boolean).join(" · ")}</p>
                      </td>
                      <td className="px-4 py-2.5">
                        <span className={`badge text-xs ${STATUS_BADGE[p.status] ?? "badge-gray"}`}>{p.status}</span>
                        {p.decided_via === "whatsapp" && (
                          <span className="badge badge-green text-[10px] ml-1"><MessageCircle size={9} className="inline mr-0.5" />WhatsApp</span>
                        )}
                        {p.decision_note && (
                          <p className="text-[11px] text-gray-400 mt-0.5 max-w-[180px] truncate" title={p.decision_note}>{p.decision_note}</p>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap">
                        {p.entry_at ? <><LogIn size={10} className="inline text-teal-600" /> {format(new Date(p.entry_at), "hh:mm a")}</> : "—"}
                        {p.exit_at && <> · <LogOut size={10} className="inline text-blue-600" /> {format(new Date(p.exit_at), "hh:mm a")}</>}
                      </td>
                      <td className="px-4 py-2.5">
                        <div className="flex gap-1.5">
                          {p.status === "pending" && (
                            <>
                              <button className="btn-secondary text-xs px-2 py-1 text-green-700"
                                onClick={() => decide.mutate({ id: p.id, decision: "approved" })}>
                                <Check size={12} /> Allow
                              </button>
                              <button className="btn-secondary text-xs px-2 py-1 text-red-600"
                                onClick={() => decide.mutate({ id: p.id, decision: "denied" })}>
                                <X size={12} /> Deny
                              </button>
                            </>
                          )}
                          {p.status === "approved" && !p.entry_at && (
                            <button className="btn-primary text-xs px-2 py-1"
                              onClick={() => move.mutate({ id: p.id, direction: "entry" })}>
                              <LogIn size={12} /> Entry
                            </button>
                          )}
                          {p.entry_at && !p.exit_at && (
                            <button className="btn-secondary text-xs px-2 py-1"
                              onClick={() => move.mutate({ id: p.id, direction: "exit" })}>
                              <LogOut size={12} /> Exit
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ── Hosts ──────────────────────────────────────────────────────── */}
      {tab === "hosts" && (
        <div className="space-y-3">
          {isManager && (
            <button className="btn-primary" onClick={() => setHostForm(HOST_INIT)}>
              <Plus size={15} /> Add host
            </button>
          )}
          <div className="card p-0 overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                <tr>
                  <th className="px-4 py-2">Name</th>
                  <th className="px-4 py-2">Phone (WhatsApp)</th>
                  <th className="px-4 py-2">Position</th>
                  <th className="px-4 py-2">Department</th>
                  <th className="px-4 py-2">Status</th>
                  {isManager && <th className="px-4 py-2">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {hLoading && (
                  <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">Loading…</td></tr>
                )}
                {!hLoading && !(hosts ?? []).length && (
                  <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">
                    No hosts yet — add the people who may receive visitors (name, mobile, department).
                  </td></tr>
                )}
                {(hosts ?? []).map((h) => (
                  <tr key={h.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-2.5 font-medium text-gray-900 flex items-center gap-2">
                      <Users size={13} className="text-gray-300" /> {h.name}
                    </td>
                    <td className="px-4 py-2.5 text-gray-600">{h.phone}</td>
                    <td className="px-4 py-2.5 text-gray-600">{h.position || "—"}</td>
                    <td className="px-4 py-2.5 text-gray-600">{h.department || "—"}</td>
                    <td className="px-4 py-2.5">
                      <span className={`badge text-xs ${h.is_active ? "badge-green" : "badge-gray"}`}>
                        {h.is_active ? "active" : "inactive"}
                      </span>
                    </td>
                    {isManager && (
                      <td className="px-4 py-2.5">
                        <div className="flex gap-1.5">
                          <button className="btn-secondary text-xs px-2 py-1" onClick={() => setHostForm(h)}>
                            <Pencil size={12} /> Edit
                          </button>
                          <button className="btn-secondary text-xs px-2 py-1"
                            onClick={() => toggleHost.mutate(h)}>
                            {h.is_active ? "Deactivate" : "Activate"}
                          </button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Host form modal */}
      {hostForm && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-3">
            <h2 className="font-semibold text-gray-900">{hostForm.id ? "Edit host" : "Add host"}</h2>
            {[
              ["name", "Full name *"],
              ["phone", "Mobile (10 digits, WhatsApp) *"],
              ["position", "Position"],
              ["department", "Department"],
            ].map(([k, label]) => (
              <div key={k}>
                <label className="label">{label}</label>
                <input className="input" value={hostForm[k] ?? ""}
                  onChange={(e) => setHostForm((f) => ({ ...f, [k]: e.target.value }))} />
              </div>
            ))}
            <div className="flex justify-end gap-2 pt-2">
              <button className="btn-secondary" onClick={() => setHostForm(null)}>Cancel</button>
              <button className="btn-primary" disabled={saveHost.isPending}
                onClick={() => saveHost.mutate(hostForm)}>
                Save
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
