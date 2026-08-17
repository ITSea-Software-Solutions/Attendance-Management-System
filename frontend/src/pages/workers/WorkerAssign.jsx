import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import toast from "react-hot-toast";
import { format } from "date-fns";
import { Plus, Lock, Unlock, X, Calendar, Building2 } from "lucide-react";

const STATUS_COLORS = {
  active:    "badge-green",
  cancelled: "badge-red",
  completed: "badge-gray",
};

export default function WorkerAssign() {
  const queryClient   = useQueryClient();
  const { user }      = useAuth();
  const isVendorAdmin = ["vendor_admin", "vendor_operator"].includes(user?.role);
  const isCompanyAdmin = user?.role === "company_admin";
  const isApprover = ["company_admin", "company_hr"].includes(user?.role) || user?.role === "super_admin";

  const today = format(new Date(), "yyyy-MM-dd");
  const [form, setForm] = useState({
    worker_id: "", company_id: "", start_date: today, end_date: "", shift: "general", notes: "",
  });
  const [showForm, setShowForm] = useState(false);
  const [tab, setTab] = useState("current"); // current | previous | all

  // My workers (vendor-scoped) — active ones only for deployment
  const { data: workers } = useQuery({
    queryKey: ["workers-active"],
    queryFn:  () => api.get("/workers", { params: { status: "active", per_page: 200 } }).then(r => r.data.data),
    enabled:  isVendorAdmin,
  });

  // Approved companies for this vendor
  const { data: companiesRaw } = useQuery({
    queryKey: ["vendor-available-companies", user?.vendor_id],
    queryFn:  () => api.get(`/vendors/${user.vendor_id}/available-companies`).then(r => r.data),
    enabled:  !!user?.vendor_id,
  });
  const approvedCompanies = companiesRaw?.filter(c => c.request_status === "approved") ?? [];

  const tabParams = {
    current:  { deployment: "current" },
    previous: { deployment: "previous" },
    all:      {},
  };

  // All my deployments (assignments)
  const { data: assignments, isLoading } = useQuery({
    queryKey: ["assignments", tab],
    queryFn:  () => api.get("/assignments", { params: { ...tabParams[tab], per_page: 100 } }).then(r => r.data),
  });

  const deploy = useMutation({
    mutationFn: (d) => api.post("/assignments", d),
    onSuccess: (r) => {
      toast.success(r.data?.message ?? "Worker deployed successfully.", { duration: 5000 });
      queryClient.invalidateQueries(["assignments"]);
      setForm({ worker_id: "", company_id: "", start_date: today, end_date: "", shift: "general", notes: "" });
      setShowForm(false);
    },
    onError: (err) => toast.error(err.response?.data?.message ?? "Deployment failed."),
  });

  // ── Company-side approvals (HR): pending list + bulk approve/reject ──
  const { data: pendingData } = useQuery({
    queryKey: ["assignments-pending"],
    queryFn:  () => api.get("/assignments-pending").then(r => r.data.pending),
    enabled:  isApprover,
    refetchInterval: 60000,
  });
  const { data: locationsData } = useQuery({
    queryKey: ["company-locations", user?.company_id],
    queryFn:  () => api.get(`/companies/${user.company_id}/locations`).then(r => r.data.locations),
    enabled:  ["company_admin", "company_hr"].includes(user?.role),
  });
  const [selIds, setSelIds] = useState([]);
  // "Main Gate" preselected: workers should always be able to IN/OUT at the
  // main gate; HR adds more departments as needed. Clearing all = every gate.
  const [selLocs, setSelLocs] = useState(["Main Gate"]);
  const [requireApproval, setRequireApproval] = useState(null);

  const approveBulk = useMutation({
    mutationFn: () => api.post("/assignments-approve", {
      ids: selIds, allowed_locations: selLocs.length ? selLocs : null,
    }),
    onSuccess: (r) => {
      toast.success(r.data.message, { duration: 5000 });
      setSelIds([]); setSelLocs([]);
      queryClient.invalidateQueries(["assignments-pending"]);
      queryClient.invalidateQueries(["assignments"]);
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Approve failed."),
  });
  const rejectOne = useMutation({
    mutationFn: ({ id, reason }) => api.post(`/assignments/${id}/reject`, { reason }),
    onSuccess: () => {
      toast.success("Deployment rejected.");
      queryClient.invalidateQueries(["assignments-pending"]);
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Reject failed."),
  });
  const saveApprovalSetting = async (v) => {
    try {
      await api.put(`/companies/${user.company_id}/settings`, { require_deployment_approval: v });
      setRequireApproval(v);
      toast.success(v ? "New deployments now need your approval." : "Deployments auto-approve again.");
    } catch { toast.error("Could not save the setting."); }
  };

  const manualOut = useMutation({
    mutationFn: (workerId) => api.post("/attendance/manual-out", { worker_id: workerId }),
    onSuccess: (r) => {
      toast.success(r.data?.message ?? "Manual OUT recorded.");
      queryClient.invalidateQueries(["assignments"]);
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Manual OUT failed."),
  });

  const cancel = useMutation({
    mutationFn: (id) => api.delete(`/assignments/${id}`),
    onSuccess: () => {
      toast.success("Deployment cancelled.");
      queryClient.invalidateQueries(["assignments"]);
    },
    onError: (err) => toast.error(err.response?.data?.message ?? "Cannot cancel."),
  });

  const f = (k) => ({ value: form[k], onChange: (e) => setForm(p => ({ ...p, [k]: e.target.value })) });

  return (
    <div className="space-y-5 max-w-5xl">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Worker Deployments</h1>
          <p className="text-sm text-gray-500 mt-0.5">Assign workers to companies for a date range</p>
        </div>
        <button onClick={() => setShowForm(v => !v)} className="btn-primary">
          <Plus size={16} />
          New Deployment
        </button>
      </div>

      {/* ── Company HR: approval requirement toggle + pending requests ── */}
      {isApprover && user?.role !== "super_admin" && (
        <div className="card space-y-3">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <h2 className="font-semibold text-gray-900">Deployment approvals</h2>
            {isCompanyAdmin && (
              <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input type="checkbox"
                       checked={requireApproval ?? false}
                       onChange={(e) => saveApprovalSetting(e.target.checked)} />
                Require approval for new vendor deployments
              </label>
            )}
          </div>
          {(pendingData?.length ?? 0) === 0 ? (
            <p className="text-sm text-gray-400">
              No deployments waiting for approval.
              {requireApproval === false || requireApproval === null
                ? " Note: approval is currently OFF — new vendor deployments activate immediately. Turn the checkbox on to review them here first."
                : " New vendor deployments will appear here for review."}
            </p>
          ) : (
            <>
              <div className="divide-y divide-gray-100">
                {pendingData.map((a) => (
                  <div key={a.id} className="flex items-center gap-3 py-2">
                    <input type="checkbox"
                           checked={selIds.includes(a.id)}
                           onChange={(e) => setSelIds((x) =>
                             e.target.checked ? [...x, a.id] : x.filter((i) => i !== a.id))} />
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {a.worker?.name} <span className="text-gray-400 font-normal">· {a.vendor?.name}</span>
                      </p>
                      <p className="text-xs text-gray-500">{a.start_date?.slice(0,10)} → {a.end_date?.slice(0,10)}</p>
                    </div>
                    <button className="btn-danger text-xs"
                            onClick={() => {
                              const reason = window.prompt("Reason for rejecting this deployment:");
                              if (reason?.trim()) rejectOne.mutate({ id: a.id, reason: reason.trim() });
                            }}>
                      Reject
                    </button>
                  </div>
                ))}
              </div>
              <div className="pt-2 border-t border-gray-100 space-y-2">
                <p className="text-xs font-medium text-gray-500 uppercase">Allowed gates / departments for the selected workers</p>
                <div className="flex flex-wrap gap-2">
                  <button onClick={() => setSelLocs([])}
                          className={`px-3 py-1 rounded-full text-xs font-medium border ${
                            selLocs.length === 0
                              ? "bg-brand-50 border-brand-500 text-brand-700"
                              : "border-gray-300 text-gray-500"
                          }`}>
                    All gates
                  </button>
                  {(locationsData ?? []).map((loc) => (
                    <button key={loc}
                            onClick={() => setSelLocs((x) =>
                              x.includes(loc) ? x.filter((l) => l !== loc) : [...x, loc])}
                            className={`px-3 py-1 rounded-full text-xs font-medium border ${
                              selLocs.includes(loc)
                                ? "bg-brand-50 border-brand-500 text-brand-700"
                                : "border-gray-300 text-gray-500"
                            }`}>
                      {loc}
                    </button>
                  ))}
                  <span className="text-xs text-gray-400 self-center">
                    {selLocs.length === 0 ? "ALL gates allowed" : `Allowed at: ${selLocs.join(", ")}`}
                  </span>
                </div>
                <button className="btn-primary text-sm"
                        disabled={selIds.length === 0 || approveBulk.isPending}
                        onClick={() => approveBulk.mutate()}>
                  Approve {selIds.length || ""} selected
                </button>
              </div>
            </>
          )}
        </div>
      )}

      {/* Create form */}
      {showForm && (
        <div className="card space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">New Deployment</h2>
            <button onClick={() => setShowForm(false)} className="p-1 rounded hover:bg-gray-100">
              <X size={16} />
            </button>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="label">Worker *</label>
              <select {...f("worker_id")} className="input">
                <option value="">Select worker...</option>
                {workers?.map(w => (
                  <option key={w.id} value={w.id}>{w.name} — {w.vendor?.name}</option>
                ))}
              </select>
              {!workers?.length && (
                <p className="text-xs text-amber-600 mt-1">No active workers. Workers need fingerprint enrolled first.</p>
              )}
            </div>

            <div>
              <label className="label">Company *</label>
              <select {...f("company_id")} className="input">
                <option value="">Select company...</option>
                {approvedCompanies.map(c => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
              {!approvedCompanies.length && (
                <p className="text-xs text-amber-600 mt-1">No approved companies. Request company access first.</p>
              )}
            </div>

            <div>
              <label className="label">Start Date *</label>
              <input type="date" {...f("start_date")} className="input" min={today} />
            </div>

            <div>
              <label className="label">End Date *</label>
              <input type="date" {...f("end_date")} className="input"
                min={form.start_date || today} />
            </div>

            <div>
              <label className="label">Shift</label>
              <select {...f("shift")} className="input">
                <option value="general">General</option>
                <option value="morning">Morning</option>
                <option value="afternoon">Afternoon</option>
                <option value="night">Night</option>
              </select>
            </div>

            <div>
              <label className="label">Notes</label>
              <input {...f("notes")} className="input" placeholder="Optional notes..." />
            </div>
          </div>

          <div className="flex gap-2 pt-2 border-t border-gray-100">
            <button
              onClick={() => deploy.mutate(form)}
              disabled={!form.worker_id || !form.company_id || !form.start_date || !form.end_date || deploy.isPending}
              className="btn-primary"
            >
              {deploy.isPending ? "Deploying..." : "Deploy Worker"}
            </button>
            <button onClick={() => setShowForm(false)} className="btn-secondary">Cancel</button>
          </div>
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 border-b border-gray-200">
        {[
          { key: "current",  label: "Current" },
          { key: "previous", label: "Previous" },
          { key: "all",      label: "All" },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t.key
                ? "border-brand-500 text-brand-700"
                : "border-transparent text-gray-500 hover:text-gray-700"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Deployments table */}
      <div className="card p-0 overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 border-b border-gray-100">
            <tr>
              <th className="text-left px-5 py-3 font-medium text-gray-500">Worker</th>
              <th className="text-left px-4 py-3 font-medium text-gray-500 hidden md:table-cell">Company</th>
              <th className="text-left px-4 py-3 font-medium text-gray-500">Period</th>
              <th className="text-center px-4 py-3 font-medium text-gray-500">Status</th>
              <th className="text-center px-4 py-3 font-medium text-gray-500">Lock</th>
              <th className="text-right px-4 py-3 font-medium text-gray-500">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i}>
                  <td colSpan={6} className="py-3 px-5">
                    <div className="h-4 bg-gray-100 rounded animate-pulse w-3/4" />
                  </td>
                </tr>
              ))
            ) : assignments?.data?.length === 0 ? (
              <tr>
                <td colSpan={6} className="text-center py-12 text-gray-400">No deployments found.</td>
              </tr>
            ) : assignments?.data?.map(a => (
              <tr key={a.id} className="hover:bg-gray-50/50">
                <td className="px-5 py-3">
                  <p className="font-medium text-gray-900">{a.worker?.name}</p>
                  <p className="text-xs text-gray-400">{a.vendor?.name}</p>
                </td>
                <td className="px-4 py-3 text-gray-600 hidden md:table-cell">
                  <div className="flex items-center gap-1.5">
                    <Building2 size={13} className="text-gray-400" />
                    {a.company?.name}
                  </div>
                </td>
                <td className="px-4 py-3 text-gray-600">
                  <div className="flex items-center gap-1.5 text-xs">
                    <Calendar size={12} className="text-gray-400" />
                    <span>{a.start_date && format(new Date(a.start_date), "dd MMM")}</span>
                    <span className="text-gray-400">→</span>
                    <span>{a.end_date && format(new Date(a.end_date), "dd MMM yyyy")}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-center">
                  <span className={`badge ${STATUS_COLORS[a.status] ?? "badge-gray"}`}>
                    {a.status}
                  </span>
                      {a.approval_status === "pending" && <span className="badge badge-yellow ml-1">awaiting approval</span>}
                      {a.approval_status === "rejected" && <span className="badge badge-red ml-1">rejected</span>}
                      {Array.isArray(a.allowed_locations) && a.allowed_locations.length > 0 && (
                        <span className="badge badge-gray ml-1">gates: {a.allowed_locations.join(", ")}</span>
                      )}
                </td>
                <td className="px-4 py-3 text-center">
                  {a.is_locked ? (
                    <span title="Attendance recorded — dates locked">
                      <Lock size={15} className="text-amber-500 inline" />
                    </span>
                  ) : (
                    <span title="No attendance yet — dates can be edited">
                      <Unlock size={15} className="text-gray-300 inline" />
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex items-center gap-3 justify-end">
                    {/* Company/HR only: administrative OUT for a forgotten
                        check-out (vendors deliberately cannot). */}
                    {a.status === "active" && ["company_admin", "company_hr", "super_admin"].includes(user?.role) && (
                      <button
                        onClick={() => {
                          if (window.confirm(`Mark ${a.worker?.name ?? "this worker"} OUT manually? Use when they left without scanning.`)) {
                            manualOut.mutate(a.worker_id);
                          }
                        }}
                        disabled={manualOut.isPending}
                        className="text-xs text-amber-600 hover:text-amber-800 font-medium"
                      >
                        Manual OUT
                      </button>
                    )}
                    {a.status === "active" && (
                      <button
                        onClick={() => cancel.mutate(a.id)}
                        disabled={cancel.isPending}
                        className="text-xs text-red-500 hover:text-red-700 font-medium"
                      >
                        Cancel
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
  );
}
