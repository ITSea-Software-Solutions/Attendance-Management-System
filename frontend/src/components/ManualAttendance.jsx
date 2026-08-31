import { useState, useEffect, useRef } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { CalendarPlus, Check, X, Clock3, Loader2 } from "lucide-react";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import { useAuth } from "@/contexts/AuthContext";
import { useOrgScope } from "@/lib/scope";

/**
 * Manual attendance — a day the gate missed, entered by hand.
 *
 * The company that pays enters it directly. A contractor may raise one, but it
 * is not attendance until the company agrees: nothing reaches attendance_logs
 * while it is pending, so a pending entry never quietly earns a day's wage.
 */
export default function ManualAttendance({ companyId, prefillWorkerId = null, onClose = null }) {
  const qc = useQueryClient();
  const { user } = useAuth();
  const { isVendorUser } = useOrgScope();
  const canDecide = ["company_admin", "company_hr", "super_admin"].includes(user?.role);
  const canRaise  = canDecide || user?.role === "vendor_admin";

  const [open, setOpen]   = useState(false);
  const openedFor = useRef(null);
  const [form, setForm]   = useState({
    worker_id: "", work_date: "", in_time: "09:00", out_time: "18:00",
    location_name: "", reason: "",
  });

  const { data: workers } = useQuery({
    queryKey: ["manual-worker-options", companyId],
    enabled: open || !!prefillWorkerId,
    queryFn: () => api.get("/workers-options", {
      params: { company_id: companyId || undefined },
    }).then((r) => r.data),
  });

  const { data: requests } = useQuery({
    queryKey: ["manual-requests", companyId],
    queryFn: () => api.get("/attendance/manual-requests", {
      params: companyId ? { company_id: companyId } : {},
    }).then((r) => r.data).catch(() => []),
    refetchInterval: 60_000,
  });
  const pending = (requests ?? []).filter((r) => r.status === "pending");

  // Opening for a specific worker fills them in and scrolls the form into view.
  useEffect(() => {
    if (!prefillWorkerId || openedFor.current === prefillWorkerId) return;
    openedFor.current = prefillWorkerId;
    setForm((f) => ({ ...f, worker_id: String(prefillWorkerId) }));
    setOpen(true);
  }, [prefillWorkerId]);

  const reset = () => setForm({
    worker_id: "", work_date: "", in_time: "09:00", out_time: "18:00",
    location_name: "", reason: "",
  });

  const save = useMutation({
    mutationFn: (body) => api.post("/attendance/manual", body),
    onSuccess: (r) => {
      toast.success(r.data?.message ?? "Recorded.", { duration: r.data?.proposed ? 7000 : 4000 });
      reset(); setOpen(false); openedFor.current = null; onClose?.();
      qc.invalidateQueries({ queryKey: ["manual-requests"] });
      qc.invalidateQueries({ queryKey: ["attendance"] });
      qc.invalidateQueries({ queryKey: ["daily-summary"] });
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not record that day."),
  });

  const decide = useMutation({
    mutationFn: ({ id, decision }) =>
      api.post(`/attendance/manual-requests/${id}/decide`, { decision }),
    onSuccess: (r) => {
      toast.success(r.data?.message ?? "Done.");
      qc.invalidateQueries({ queryKey: ["manual-requests"] });
      qc.invalidateQueries({ queryKey: ["attendance"] });
      qc.invalidateQueries({ queryKey: ["daily-summary"] });
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not record the decision."),
  });

  if (!canRaise) return null;

  const today = new Date().toISOString().slice(0, 10);
  const ready = form.worker_id && form.work_date && form.in_time && form.reason.trim().length >= 3;

  return (
    <div className="space-y-3">
      {/* ── entries waiting on the company ── */}
      {pending.length > 0 && (
        <div className="card space-y-3">
          <h2 className="font-semibold text-gray-900 flex items-center gap-2">
            <Clock3 size={16} className="text-amber-500" />
            {isVendorUser ? "Waiting for the company to approve" : "Manual attendance to approve"}
          </h2>
          <p className="text-sm text-gray-500">
            {isVendorUser
              ? "These days are not counted, and not paid, until the company agrees."
              : "A contractor says these days were worked. Nothing is counted until you approve."}
          </p>
          <div className="divide-y divide-gray-50 border-t border-gray-100">
            {pending.map((r) => (
              <div key={r.id} className="flex items-center justify-between gap-3 py-2.5 flex-wrap">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-gray-900">
                    {r.worker?.name}
                    {r.vendor?.name && <span className="text-gray-400 font-normal"> · {r.vendor.name}</span>}
                  </p>
                  <p className="text-[12px] text-gray-500">
                    {r.work_date} · {r.in_at?.slice(11)}
                    {r.out_at ? `–${r.out_at.slice(11)}` : " (no OUT)"}
                    {r.hours ? ` · ${r.hours}h` : ""} · {r.reason}
                  </p>
                </div>
                {canDecide ? (
                  <div className="flex gap-2">
                    <button className="btn-secondary text-xs py-1 text-green-700"
                      disabled={decide.isPending}
                      onClick={() => decide.mutate({ id: r.id, decision: "approved" })}>
                      <Check size={13} /> Approve
                    </button>
                    <button className="btn-secondary text-xs py-1 text-red-600"
                      disabled={decide.isPending}
                      onClick={() => decide.mutate({ id: r.id, decision: "rejected" })}>
                      <X size={13} /> Reject
                    </button>
                  </div>
                ) : (
                  <span className="badge badge-yellow text-xs">Awaiting approval</span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ── add a missed day ── */}
      <div className="card py-3">
        <button className="btn-secondary text-sm"
          onClick={() => { const n = !open; setOpen(n); if (!n) { openedFor.current = null; onClose?.(); } }}>
          <CalendarPlus size={14} /> {open ? "Close" : "Add a missed day"}
        </button>

        {open && (
          <div className="mt-3 space-y-3">
            <p className="text-sm text-gray-500">
              {canDecide
                ? "For a day the scanner missed. It is recorded as a manual entry, with your reason attached, and counts towards wages straight away."
                : "For a day the scanner missed. The company has to approve it before it counts towards wages."}
            </p>

            <div className="grid md:grid-cols-3 gap-3">
              <div className="md:col-span-2">
                <label className="label">Worker</label>
                <select className="input" value={form.worker_id}
                  onChange={(e) => setForm({ ...form, worker_id: e.target.value })}>
                  <option value="">Choose a worker…</option>
                  {(workers ?? []).map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.emp_code ? `${w.name} · #${w.emp_code}` : w.name}
                      {w.vendor ? ` — ${typeof w.vendor === "object" ? w.vendor.name : w.vendor}` : ""}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="label">Date worked</label>
                <input className="input" type="date" max={today} value={form.work_date}
                  onChange={(e) => setForm({ ...form, work_date: e.target.value })} />
              </div>
              <div>
                <label className="label">IN time</label>
                <input className="input" type="time" value={form.in_time}
                  onChange={(e) => setForm({ ...form, in_time: e.target.value })} />
              </div>
              <div>
                <label className="label">OUT time</label>
                <input className="input" type="time" value={form.out_time}
                  onChange={(e) => setForm({ ...form, out_time: e.target.value })} />
                <p className="text-[11px] text-gray-400 mt-1">
                  Leave blank if they never scanned out. An earlier time counts as a night shift.
                </p>
              </div>
              <div>
                <label className="label">Gate / department</label>
                <input className="input" placeholder="Main Gate" value={form.location_name}
                  onChange={(e) => setForm({ ...form, location_name: e.target.value })} />
              </div>
              <div className="md:col-span-3">
                <label className="label">Why is this being entered by hand? *</label>
                <input className="input" maxLength={500}
                  placeholder="Scanner was down at the gate / worker forgot to scan out"
                  value={form.reason}
                  onChange={(e) => setForm({ ...form, reason: e.target.value })} />
                <p className="text-[11px] text-gray-400 mt-1">
                  Stored with the record permanently — it is the evidence for a day nobody scanned.
                </p>
              </div>
            </div>

            <div className="flex gap-2">
              <button className="btn-primary text-sm" disabled={!ready || save.isPending}
                onClick={() => save.mutate({ ...form, company_id: companyId || undefined })}>
                {save.isPending ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
                {canDecide ? "Record this day" : "Send for approval"}
              </button>
              <button className="btn-secondary text-sm"
                onClick={() => { reset(); setOpen(false); openedFor.current = null; onClose?.(); }}>
                Cancel
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
