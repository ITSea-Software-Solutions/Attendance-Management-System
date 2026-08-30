import { useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import PageHint from "@/components/PageHint";
import MultiSelect from "@/components/MultiSelect";
import { useOrgScope } from "@/lib/scope";
import { Donut, HBarList } from "@/components/charts";
import {
  IndianRupee, Download, FileSpreadsheet, Loader2, AlertTriangle, Save,
  CalendarRange, Plus, X, Building2, Clock3,
} from "lucide-react";

/** Pay period containing `anchor` for a cycle starting on `startDay`. */
function cyclePeriod(anchor, startDay) {
  const d = new Date(anchor);
  if (startDay <= 1) {
    const s = new Date(d.getFullYear(), d.getMonth(), 1);
    const e = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    return [iso(s), iso(e)];
  }
  const start = d.getDate() >= startDay
    ? new Date(d.getFullYear(), d.getMonth(), startDay)
    : new Date(d.getFullYear(), d.getMonth() - 1, startDay);
  const end = new Date(start.getFullYear(), start.getMonth() + 1, startDay - 1);
  return [iso(start), iso(end)];
}
const iso = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const money = (n) =>
  "₹" + Number(n || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });

const FLAG_LABEL = {
  no_rate: "No wage rate set",
  missing_out: "Missing OUT",
  absent_with_ot: "OT on an absent day",
  worked_on_off: "Worked on off/holiday",
  unapproved_ot: "Unapproved OT",
};
const flagText = (f) => {
  const [k, n] = f.split(":");
  return FLAG_LABEL[k] ? (n ? `${FLAG_LABEL[k]} (${n})` : FLAG_LABEL[k]) : f;
};

export default function Payroll() {
  const qc = useQueryClient();
  const { isCompanyUser, isVendorUser } = useOrgScope();

  const [startDay, setStartDay] = useState(26);
  const [[from, to], setRange] = useState(() => cyclePeriod(new Date(), 26));
  const [companyId, setCompanyId] = useState("");
  const [workerIds, setWorkerIds] = useState([]);
  const [tab, setTab] = useState("register");        // register | contractors
  const [rateEdits, setRateEdits] = useState({});     // worker_id -> rate
  const [adjFor, setAdjFor] = useState(null);         // row being adjusted

  const scope = {
    from, to,
    ...(companyId ? { company_id: companyId } : {}),
    ...(workerIds.length ? { worker_ids: workerIds.join(",") } : {}),
  };
  const ready = isCompanyUser || !!companyId;

  const { data: companies } = useQuery({
    queryKey: ["company-options"],
    queryFn: () => api.get("/companies", { params: { per_page: 100 } })
      .then((r) => r.data?.data ?? r.data),
    enabled: !isCompanyUser,
    staleTime: 5 * 60_000,
  });

  const { data: workerOptions } = useQuery({
    queryKey: ["worker-options", companyId],
    queryFn: () => api.get("/workers-options", { params: { company_id: companyId || undefined } })
      .then((r) => r.data),
    staleTime: 5 * 60_000,
  });

  const { data, isFetching, isError, error } = useQuery({
    queryKey: ["payroll-register", scope],
    queryFn: () => api.get("/payroll/register", { params: scope }).then((r) => r.data),
    enabled: ready,
    keepPreviousData: true,
  });

  const { data: contractors } = useQuery({
    queryKey: ["payroll-contractors", scope],
    queryFn: () => api.get("/payroll/contractor-summary", { params: scope }).then((r) => r.data),
    enabled: ready && tab === "contractors",
  });

  const saveRates = useMutation({
    mutationFn: (rates) => api.post("/payroll/rates", { rates }),
    onSuccess: (r) => {
      toast.success(r.data?.message ?? "Rates saved.");
      setRateEdits({});
      qc.invalidateQueries({ queryKey: ["payroll-register"] });
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not save rates."),
  });

  const addAdjustment = useMutation({
    mutationFn: (body) => api.post("/payroll/adjustments", body, { params: { from, to, ...(companyId ? { company_id: companyId } : {}) } }),
    onSuccess: () => {
      toast.success("Adjustment saved.");
      setAdjFor(null);
      qc.invalidateQueries({ queryKey: ["payroll-register"] });
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not save adjustment."),
  });

  const download = async (path, filename) => {
    try {
      const r = await api.get(path, { params: scope, responseType: "blob" });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Download failed.");
    }
  };

  const downloadExcel = async () => {
    try {
      const XLSX = await import("xlsx");
      const r = await api.get("/payroll/register-export", { params: scope, responseType: "blob" });
      const wb = XLSX.read(await r.data.text(), { type: "string", raw: true });
      const ws = wb.Sheets[wb.SheetNames[0]];
      for (const c of Object.keys(ws)) {
        if (c[0] !== "!" && typeof ws[c].v === "string" && /^'[=+\-@]/.test(ws[c].v)) ws[c].v = ws[c].v.slice(1);
      }
      const out = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(out, ws, "Wage register");
      XLSX.writeFile(out, `truecrew-wage-register-${from}_to_${to}.xlsx`);
    } catch {
      toast.error("Excel export failed.");
    }
  };

  const rows = data?.rows ?? [];
  const totals = data?.totals;

  const flagged = useMemo(() => rows.filter((r) => r.flags?.length), [rows]);
  const donut = useMemo(() => {
    if (!totals) return [];
    return [
      { label: "Basic", value: Math.round(totals.base_amount) },
      { label: "Overtime", value: Math.round(totals.ot_amount) },
    ].filter((x) => x.value > 0);
  }, [totals]);

  const shiftCycle = (months) => {
    const anchor = new Date(from);
    anchor.setMonth(anchor.getMonth() + months);
    anchor.setDate(Math.min(startDay, 28));
    setRange(cyclePeriod(anchor, startDay));
  };

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Payroll &amp; Wage Register</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Attendance converted to payable wages — day rate × present days + overtime
          </p>
        </div>
        <div className="flex gap-2 flex-wrap">
          <button className="btn-secondary text-sm" onClick={downloadExcel}>
            <FileSpreadsheet size={14} /> Register (Excel)
          </button>
          <button className="btn-secondary text-sm"
            onClick={() => download("/payroll/register-export", `wage-register-${from}_to_${to}.csv`)}>
            <Download size={14} /> Register CSV
          </button>
          <button className="btn-secondary text-sm"
            onClick={() => download("/payroll/muster", `muster-${from}_to_${to}.csv`)}>
            <Download size={14} /> Muster sheet
          </button>
        </div>
      </div>

      <PageHint id="payroll">
        This is your muster and wage sheet, filled in from real gate punches. Set each
        worker's monthly rate once — the day rate (rate ÷ 26) and overtime rate
        (day rate ÷ 8) follow automatically. <b>Muster sheet</b> downloads the familiar
        day-by-day grid with P / A / WO codes and the overtime row beneath.
      </PageHint>

      {/* ── period + scope ── */}
      <div className="card !py-3.5 flex flex-wrap items-center gap-3">
        <span className="inline-flex items-center gap-1.5 text-sm font-medium text-gray-700">
          <CalendarRange size={15} /> Pay cycle
        </span>
        <select className="input w-auto py-1.5 text-sm" value={startDay}
          onChange={(e) => {
            const d = Number(e.target.value);
            setStartDay(d);
            setRange(cyclePeriod(new Date(from), d));
          }}>
          <option value={26}>26th → 25th</option>
          <option value={21}>21st → 20th</option>
          <option value={16}>16th → 15th</option>
          <option value={1}>Calendar month</option>
        </select>
        <div className="flex items-center gap-1">
          <button className="btn-secondary text-sm py-1" onClick={() => shiftCycle(-1)}>‹ Prev</button>
          <span className="text-sm font-medium text-gray-800 px-2 whitespace-nowrap">{from} → {to}</span>
          <button className="btn-secondary text-sm py-1" onClick={() => shiftCycle(1)}>Next ›</button>
        </div>
        {!isCompanyUser && (
          <select className="input w-auto py-1.5 text-sm" value={companyId}
            onChange={(e) => { setCompanyId(e.target.value); setWorkerIds([]); }}>
            <option value="">Select company…</option>
            {(companies ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        )}
        <MultiSelect label="Workers" width="w-48"
          options={(workerOptions ?? []).map((w) => ({
            id: w.id, name: w.emp_code ? `${w.name} · #${w.emp_code}` : w.name, sub: w.vendor,
          }))}
          value={workerIds} onChange={setWorkerIds} />
        {isFetching && <Loader2 size={15} className="animate-spin text-gray-400" />}
      </div>

      {!ready && (
        <div className="card text-center py-10 text-gray-500">
          Select a company to load its wage register.
        </div>
      )}
      {isError && (
        <div className="card text-center py-8 text-red-600">
          {error?.response?.data?.message ?? "Could not load the register."}
        </div>
      )}

      {ready && totals && (
        <>
          {/* ── totals ── */}
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
            {[
              { label: "Workers", value: totals.workers, icon: Building2 },
              { label: "Present days", value: totals.present_days, icon: CalendarRange },
              { label: "Overtime hours", value: totals.ot_hours, icon: Clock3 },
              { label: "Basic + OT", value: money(totals.base_amount + totals.ot_amount), icon: IndianRupee },
              { label: "Net payable", value: money(totals.net_payable), icon: IndianRupee, hot: true },
            ].map((k) => (
              <div key={k.label} className={`card ${k.hot ? "ring-1 ring-brand-500 bg-brand-50/40" : ""}`}>
                <p className="text-[12.5px] text-gray-500 flex items-center gap-1.5"><k.icon size={13} />{k.label}</p>
                <p className="text-xl font-bold text-gray-900 mt-1">{k.value}</p>
              </div>
            ))}
          </div>

          {flagged.length > 0 && (
            <div className="card !py-3 border-l-4 border-amber-400">
              <p className="text-sm font-semibold text-amber-800 flex items-center gap-1.5 mb-1.5">
                <AlertTriangle size={15} /> {flagged.length} row(s) need a look before you release payment
              </p>
              <div className="flex flex-wrap gap-1.5">
                {flagged.slice(0, 12).map((r) => (
                  <span key={r.worker_id} className="text-[12px] bg-amber-50 border border-amber-200 rounded-full px-2.5 py-1">
                    <b>{r.name}</b> — {r.flags.map(flagText).join(", ")}
                  </span>
                ))}
              </div>
            </div>
          )}

          <div className="flex gap-1 border-b border-gray-200">
            {[["register", "Wage register"], ["contractors", "By contractor"]].map(([k, label]) => (
              <button key={k} onClick={() => setTab(k)}
                className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                  tab === k ? "border-brand-500 text-brand-700" : "border-transparent text-gray-500 hover:text-gray-700"}`}>
                {label}
              </button>
            ))}
          </div>

          {tab === "register" ? (
            <div className="card p-0 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 border-b border-gray-100">
                    <tr>
                      <th className="text-left px-4 py-2.5 font-medium text-gray-500">Worker</th>
                      <th className="text-center px-2 py-2.5 font-medium text-gray-500">P</th>
                      <th className="text-center px-2 py-2.5 font-medium text-gray-500">A</th>
                      <th className="text-center px-2 py-2.5 font-medium text-gray-500">WO</th>
                      <th className="text-center px-2 py-2.5 font-medium text-gray-500">OT hrs</th>
                      <th className="text-right px-3 py-2.5 font-medium text-gray-500">Monthly rate</th>
                      <th className="text-right px-3 py-2.5 font-medium text-gray-500 hidden md:table-cell">Day rate</th>
                      <th className="text-right px-3 py-2.5 font-medium text-gray-500 hidden lg:table-cell">Basic</th>
                      <th className="text-right px-3 py-2.5 font-medium text-gray-500 hidden lg:table-cell">OT amt</th>
                      <th className="text-right px-3 py-2.5 font-medium text-gray-500">Net payable</th>
                      <th className="text-right px-3 py-2.5 font-medium text-gray-500"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {rows.map((r) => (
                      <tr key={r.worker_id} className="hover:bg-gray-50/60">
                        <td className="px-4 py-2">
                          <p className="font-medium text-gray-900 leading-tight">{r.name}</p>
                          <p className="text-[11px] text-gray-400">{r.vendor}{r.emp_code ? ` · #${r.emp_code}` : ""}</p>
                        </td>
                        <td className="text-center px-2 font-medium text-emerald-700">{r.present_days}</td>
                        <td className="text-center px-2 text-gray-500">{r.absent_days}</td>
                        <td className="text-center px-2 text-gray-400">{r.off_days + r.holidays}</td>
                        <td className="text-center px-2 text-gray-700">{r.ot_hours || "—"}</td>
                        <td className="px-3 py-2 text-right">
                          <input
                            type="number" min="0" step="100"
                            className={`input py-1 text-sm text-right w-28 ${!r.monthly_rate ? "border-amber-400 bg-amber-50" : ""}`}
                            value={rateEdits[r.worker_id] ?? r.monthly_rate ?? ""}
                            placeholder="set rate"
                            onChange={(e) => setRateEdits((p) => ({ ...p, [r.worker_id]: e.target.value }))}
                          />
                        </td>
                        <td className="px-3 text-right text-gray-600 hidden md:table-cell">{r.day_rate ? money(r.day_rate) : "—"}</td>
                        <td className="px-3 text-right text-gray-600 hidden lg:table-cell">{money(r.base_amount)}</td>
                        <td className="px-3 text-right text-gray-600 hidden lg:table-cell">{money(r.ot_amount)}</td>
                        <td className="px-3 text-right font-semibold text-gray-900">
                          {money(r.net_payable)}
                          {(r.arrear || r.bonus || r.advance || r.deduction) > 0 && (
                            <span className="block text-[10.5px] font-normal text-gray-400">
                              incl. adjustments
                            </span>
                          )}
                        </td>
                        <td className="px-3 text-right">
                          {!isVendorUser && (
                            <button className="text-xs text-brand-700 hover:underline whitespace-nowrap"
                              onClick={() => setAdjFor(r)}>
                              <Plus size={11} className="inline" /> adjust
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                    {rows.length === 0 && (
                      <tr><td colSpan={11} className="text-center py-10 text-gray-400">
                        No workers in this pay cycle.
                      </td></tr>
                    )}
                  </tbody>
                </table>
              </div>

              {Object.keys(rateEdits).length > 0 && (
                <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100 bg-brand-50/50">
                  <p className="text-sm text-gray-700">
                    {Object.keys(rateEdits).length} rate(s) changed
                  </p>
                  <div className="flex gap-2">
                    <button className="btn-secondary text-sm" onClick={() => setRateEdits({})}>Cancel</button>
                    <button className="btn-primary text-sm" disabled={saveRates.isPending}
                      onClick={() => saveRates.mutate(Object.entries(rateEdits).map(([id, v]) => ({
                        worker_id: Number(id), monthly_rate: Number(v) || 0,
                      })))}>
                      <Save size={14} /> Save rates
                    </button>
                  </div>
                </div>
              )}
            </div>
          ) : (
            <div className="grid lg:grid-cols-2 gap-4">
              <div className="card">
                <h2 className="font-semibold text-gray-900 mb-3">What each contractor should bill</h2>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="text-left px-3 py-2 font-medium text-gray-500">Contractor</th>
                        <th className="text-center px-2 py-2 font-medium text-gray-500">Workers</th>
                        <th className="text-center px-2 py-2 font-medium text-gray-500">Days</th>
                        <th className="text-center px-2 py-2 font-medium text-gray-500">OT hrs</th>
                        <th className="text-right px-3 py-2 font-medium text-gray-500">Payable</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                      {(contractors?.rows ?? []).map((c) => (
                        <tr key={c.contractor}>
                          <td className="px-3 py-2 font-medium text-gray-800">{c.contractor}</td>
                          <td className="text-center px-2">{c.workers}</td>
                          <td className="text-center px-2">{c.present_days}</td>
                          <td className="text-center px-2">{c.ot_hours}</td>
                          <td className="text-right px-3 font-semibold">{money(c.net_payable)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="card">
                <h2 className="font-semibold text-gray-900 mb-3">Where the money goes</h2>
                <Donut segments={donut} centerLabel="rupees" />
                <div className="mt-4 pt-3 border-t border-gray-50">
                  <p className="text-[13px] font-semibold text-gray-700 mb-2">Payable by contractor</p>
                  <HBarList color="#10b981"
                    rows={(contractors?.rows ?? []).map((c) => ({
                      label: c.contractor, value: c.net_payable, display: money(c.net_payable),
                    }))} />
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {/* ── adjustment dialog ── */}
      {adjFor && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
          onClick={() => setAdjFor(null)}>
          <div className="bg-white rounded-2xl w-full max-w-md p-5 space-y-4" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between">
              <div>
                <h3 className="font-bold text-gray-900">Adjustment</h3>
                <p className="text-xs text-gray-500">{adjFor.name} · {from} → {to}</p>
              </div>
              <button className="text-gray-400 hover:text-gray-600" onClick={() => setAdjFor(null)}><X size={18} /></button>
            </div>
            <form className="space-y-3" onSubmit={(e) => {
              e.preventDefault();
              const f = new FormData(e.currentTarget);
              addAdjustment.mutate({
                worker_id: adjFor.worker_id,
                type: f.get("type"),
                amount: Number(f.get("amount")),
                note: f.get("note") || undefined,
              });
            }}>
              <div>
                <label className="label">Type</label>
                <select name="type" className="input" defaultValue="arrear">
                  <option value="arrear">Arrears (add)</option>
                  <option value="bonus">Bonus (add)</option>
                  <option value="advance">Advance (deduct)</option>
                  <option value="deduction">Deduction (deduct)</option>
                </select>
              </div>
              <div>
                <label className="label">Amount (₹)</label>
                <input name="amount" type="number" min="1" step="1" required className="input" />
              </div>
              <div>
                <label className="label">Note</label>
                <input name="note" className="input" placeholder="e.g. July shortfall" />
              </div>
              <div className="flex gap-2 pt-1">
                <button type="submit" className="btn-primary" disabled={addAdjustment.isPending}>Save</button>
                <button type="button" className="btn-secondary" onClick={() => setAdjFor(null)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
