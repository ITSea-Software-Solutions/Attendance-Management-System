import { useEffect, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import PageHint from "@/components/PageHint";
import { useOrgScope } from "@/lib/scope";
import { Settings, Plus, CalendarDays, Clock3, Save, Loader2 } from "lucide-react";

const GRADES = [
  ["unskilled", "Unskilled"],
  ["semi_skilled", "Semi-skilled"],
  ["skilled", "Skilled"],
  ["highly_skilled", "Highly skilled"],
];
const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

/**
 * Payroll settings that belong to the company rather than to one worker:
 * the holiday calendar, weekly offs, and the overtime rate per skill grade.
 */
export default function PayrollSettings() {
  const qc = useQueryClient();
  const { isCompanyUser } = useOrgScope();

  const [companyId, setCompanyId] = useState("");
  const [otRates, setOtRates] = useState({});
  const [offs, setOffs] = useState(null);

  const { data: me } = useQuery({ queryKey: ["me"], queryFn: () => api.get("/auth/me").then((r) => r.data) });
  const cid = isCompanyUser ? me?.company_id : companyId;
  const params = companyId ? { company_id: companyId } : {};

  const { data: companies } = useQuery({
    queryKey: ["company-options"],
    queryFn: () => api.get("/companies", { params: { per_page: 100 } }).then((r) => r.data?.data ?? r.data),
    enabled: !isCompanyUser,
    staleTime: 5 * 60_000,
  });

  const { data: company } = useQuery({
    queryKey: ["company-detail", cid],
    enabled: !!cid,
    queryFn: () => api.get(`/companies/${cid}`).then((r) => r.data),
  });

  const { data: rules } = useQuery({
    queryKey: ["payroll-components"],
    queryFn: () => api.get("/payroll/components").then((r) => r.data),
    staleTime: 30 * 60_000,
  });

  const { data: holidays } = useQuery({
    queryKey: ["company-holidays", cid],
    enabled: !!cid,
    queryFn: () => api.get("/payroll/holidays", { params }).then((r) => r.data),
  });

  useEffect(() => {
    if (!company) return;
    setOtRates(company.settings?.ot_multipliers ?? {});
    setOffs(company.settings?.weekly_offs ?? [0]);
  }, [company]);

  const saveSettings = useMutation({
    mutationFn: (body) => api.put(`/companies/${cid}/settings`, body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["company-detail"] });
      qc.invalidateQueries({ queryKey: ["payroll-register"] });
      toast.success("Saved.");
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not save."),
  });

  const addHoliday = useMutation({
    mutationFn: (body) => api.post("/payroll/holidays", body, { params }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["company-holidays"] });
      qc.invalidateQueries({ queryKey: ["payroll-register"] });
      toast.success("Holiday saved — it is paid, and working it earns overtime.");
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not save the holiday."),
  });

  const removeHoliday = useMutation({
    mutationFn: (id) => api.delete(`/payroll/holidays/${id}`, { params }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["company-holidays"] });
      qc.invalidateQueries({ queryKey: ["payroll-register"] });
    },
  });

  const holidayMult = rules?.statutory ? undefined : undefined; // read from register rules below

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Settings size={21} className="text-brand-600" /> Payroll Settings
        </h1>
        <p className="text-sm text-gray-500 mt-0.5">
          Holidays, weekly offs and overtime rates — these apply to everyone at this company.
        </p>
      </div>

      <PageHint id="payroll-settings">
        A <b>government holiday</b> is paid at the day rate to everyone deployed, and anyone who
        works it earns overtime for the whole day. A <b>weekly off</b> is not paid, but working
        one is also overtime. Overtime rates can differ by skill grade, and a single worker's
        own rate always wins over these.
      </PageHint>

      {!isCompanyUser && (
        <div className="card !py-3.5 flex items-center gap-3">
          <span className="text-sm font-medium text-gray-700">Company</span>
          <select className="input w-auto py-1.5 text-sm" value={companyId}
            onChange={(e) => setCompanyId(e.target.value)}>
            <option value="">Select company…</option>
            {(companies ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
      )}

      {!cid ? (
        <div className="card text-center py-10 text-gray-500">Select a company to load its settings.</div>
      ) : (
        <div className="grid lg:grid-cols-2 gap-4">
          {/* ── Holidays ── */}
          <div className="card space-y-4">
            <h2 className="font-semibold text-gray-900 flex items-center gap-2">
              <CalendarDays size={16} className="text-brand-600" /> Government &amp; festival holidays
            </h2>
            <form className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                addHoliday.mutate({
                  holiday_date: f.get("holiday_date"),
                  name: f.get("name"),
                  paid: f.get("paid") === "on",
                });
                e.currentTarget.reset();
              }}>
              <div>
                <label className="label">Date</label>
                <input name="holiday_date" type="date" required className="input w-40 py-1.5 text-sm" />
              </div>
              <div className="flex-1 min-w-[150px]">
                <label className="label">Occasion</label>
                <input name="name" required maxLength={120} className="input py-1.5 text-sm"
                  placeholder="e.g. Republic Day" />
              </div>
              <label className="flex items-center gap-1.5 text-sm text-gray-700 pb-2">
                <input name="paid" type="checkbox" defaultChecked className="w-4 h-4" /> Paid
              </label>
              <button className="btn-primary text-sm" disabled={addHoliday.isPending}>
                <Plus size={14} /> Add
              </button>
            </form>

            <div className="divide-y divide-gray-50 border-t border-gray-100 max-h-72 overflow-y-auto">
              {(holidays ?? []).length === 0 && (
                <p className="text-sm text-gray-400 py-6 text-center">
                  No holidays yet — add the year's national and festival holidays.
                </p>
              )}
              {(holidays ?? []).map((h) => (
                <div key={h.id} className="flex items-center justify-between py-2">
                  <div>
                    <p className="text-sm font-medium text-gray-800">{h.name}</p>
                    <p className="text-[11px] text-gray-400">{h.holiday_date}{h.paid ? " · paid" : " · unpaid"}</p>
                  </div>
                  <button className="text-xs text-red-600 hover:underline"
                    onClick={() => removeHoliday.mutate(h.id)}>Remove</button>
                </div>
              ))}
            </div>
          </div>

          {/* ── Weekly offs + OT ── */}
          <div className="space-y-4">
            <div className="card space-y-3">
              <h2 className="font-semibold text-gray-900">Weekly off</h2>
              <p className="text-sm text-gray-500">
                Days marked <b>WO</b> on the muster instead of absent. Working one is overtime.
              </p>
              <div className="flex flex-wrap gap-1.5">
                {DAYS.map((d, i) => {
                  const on = (offs ?? []).includes(i);
                  return (
                    <button key={d} type="button"
                      onClick={() => setOffs(on ? offs.filter((x) => x !== i) : [...(offs ?? []), i])}
                      className={`rounded-lg px-2.5 py-1 text-[12.5px] font-medium border ${
                        on ? "bg-brand-50 text-brand-700 border-brand-200" : "bg-white text-gray-500 border-gray-200"}`}>
                      {d.slice(0, 3)}
                    </button>
                  );
                })}
              </div>
              <button className="btn-primary text-sm" disabled={saveSettings.isPending}
                onClick={() => saveSettings.mutate({ weekly_offs: offs ?? [] })}>
                <Save size={14} /> Save weekly offs
              </button>
            </div>

            <div className="card space-y-3">
              <h2 className="font-semibold text-gray-900 flex items-center gap-2">
                <Clock3 size={16} className="text-brand-600" /> Overtime rate by grade
              </h2>
              <p className="text-sm text-gray-500">
                A multiplier on the hourly rate (day rate ÷ {rules?.defaults?.ot_divisor ?? 8}).
                1 = single rate, 2 = double. A worker's own override wins over these.
              </p>
              <div className="grid grid-cols-2 gap-3">
                {GRADES.map(([key, label]) => (
                  <div key={key}>
                    <label className="label">{label}</label>
                    <input type="number" min="0" max="4" step="0.25" className="input py-1.5 text-sm"
                      placeholder="1" value={otRates[key] ?? ""}
                      onChange={(e) => setOtRates({ ...otRates, [key]: e.target.value })} />
                  </div>
                ))}
              </div>
              <button className="btn-primary text-sm" disabled={saveSettings.isPending}
                onClick={() => saveSettings.mutate({
                  ot_multipliers: Object.fromEntries(
                    Object.entries(otRates).filter(([, v]) => v !== "" && v != null)
                      .map(([k, v]) => [k, Number(v)])),
                })}>
                <Save size={14} /> Save overtime rates
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
