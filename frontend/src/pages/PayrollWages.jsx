import { useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import PageHint from "@/components/PageHint";
import MultiSelect from "@/components/MultiSelect";
import { useOrgScope } from "@/lib/scope";
import { IndianRupee, Save, Search, AlertTriangle, Loader2, Building2 } from "lucide-react";

const money = (n) => "₹" + Number(n || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });

/**
 * Workers & wages — set what each worker is paid.
 *
 * Contract labour is hired at a rate PER DAY, so that is the default here.
 * Rates live on the worker record, which the contractor owns: whichever side
 * sets an amount, the other sees it immediately.
 */
export default function PayrollWages() {
  const qc = useQueryClient();
  const { isCompanyUser, isVendorUser } = useOrgScope();

  const [companyId, setCompanyId] = useState("");
  const [vendorIds, setVendorIds] = useState([]);   // [] = all contractors
  const [search, setSearch] = useState("");
  const [edits, setEdits] = useState({});           // worker_id -> { wage_type, rate }

  const ready = isCompanyUser || isVendorUser || !!companyId;

  const { data: companies } = useQuery({
    queryKey: ["company-options"],
    queryFn: () => api.get("/companies", { params: { per_page: 100 } }).then((r) => r.data?.data ?? r.data),
    enabled: !isCompanyUser,
    staleTime: 5 * 60_000,
  });

  const { data: vendorOpts } = useQuery({
    queryKey: ["vendor-options-wages", companyId],
    enabled: !isVendorUser,
    queryFn: async () => {
      const r = isCompanyUser
        ? await api.get(`/companies/${(await api.get("/auth/me")).data.company_id}/vendors`)
        : await api.get("/vendors", { params: { per_page: 100 } });
      const rows = r.data?.data ?? r.data ?? [];
      return (Array.isArray(rows) ? rows : []).map((v) => ({ id: v.id, name: v.name }));
    },
  });

  // The people to price: everyone in scope, not only those deployed today.
  const { data: workers, isFetching } = useQuery({
    queryKey: ["wage-workers", companyId],
    queryFn: () => api.get("/workers-options", {
      params: { company_id: companyId || undefined },
    }).then((r) => r.data),
    enabled: ready,
  });

  const { data: detail } = useQuery({
    queryKey: ["wage-worker-detail", (workers ?? []).map((w) => w.id).join(",")],
    enabled: !!workers?.length,
    queryFn: async () => {
      // The picker list is deliberately compact, so pull the wage fields for
      // the same set in one paged call rather than N requests.
      const r = await api.get("/workers", { params: { per_page: 200 } });
      const rows = r.data?.data ?? [];
      return Object.fromEntries(rows.map((w) => [w.id, w]));
    },
  });

  const rows = useMemo(() => {
    const q = search.trim().toLowerCase();
    return (workers ?? [])
      .map((w) => {
        const d = detail?.[w.id] ?? {};
        // /workers-options gives vendor as a NAME; /workers gives an object.
        // Merging blind renders an object into a cell and crashes the page.
        const vendorName = typeof d.vendor === "object" ? d.vendor?.name : (d.vendor ?? w.vendor);
        return { ...w, ...d, vendor: vendorName ?? null };
      })
      .filter((w) => !vendorIds.length || vendorIds.includes(w.vendor_id))
      .filter((w) => !q || `${w.name} ${w.emp_code ?? ""} ${w.vendor ?? ""}`.toLowerCase().includes(q));
  }, [workers, detail, vendorIds, search]);

  const priced = rows.filter((w) => Number(w.daily_rate) > 0 || Number(w.monthly_rate) > 0).length;

  const save = useMutation({
    mutationFn: () => api.post("/payroll/rates", {
      rates: Object.entries(edits).map(([id, e]) => ({
        worker_id: Number(id),
        wage_type: e.wage_type,
        ...(e.wage_type === "daily"
          ? { daily_rate: Number(e.rate) || 0 }
          : { monthly_rate: Number(e.rate) || 0 }),
      })),
    }),
    onSuccess: (r) => {
      toast.success(r.data?.message ?? "Rates saved.");
      setEdits({});
      qc.invalidateQueries({ queryKey: ["wage-worker-detail"] });
      qc.invalidateQueries({ queryKey: ["payroll-register"] });
    },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not save rates."),
  });

  const rowFor = (w) => {
    const e = edits[w.id];
    const type = e?.wage_type ?? w.wage_type ?? "daily";
    const rate = e?.rate ?? (type === "daily" ? w.daily_rate : w.monthly_rate) ?? "";
    return { type, rate };
  };
  const setRow = (w, patch) => {
    const cur = rowFor(w);
    setEdits((p) => ({ ...p, [w.id]: { ...cur, ...patch } }));
  };

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <IndianRupee size={22} className="text-brand-600" /> Workers &amp; Wages
        </h1>
        <p className="text-sm text-gray-500 mt-0.5">
          What each worker is paid. Rates feed the wage register straight away.
        </p>
      </div>

      <PageHint id="payroll-wages">
        Daily-wage labour is paid a <b>rate per day</b> for each day present — that is the
        default. Switch a supervisor or staff member to <b>per month</b> and their day rate
        becomes the monthly figure ÷ 26. Whoever sets the amount, company or contractor,
        the other side sees the same number.
      </PageHint>

      <div className="card !py-3.5 flex flex-wrap items-center gap-3">
        {!isCompanyUser && !isVendorUser && (
          <select className="input w-auto py-1.5 text-sm" value={companyId}
            onChange={(e) => setCompanyId(e.target.value)}>
            <option value="">All companies</option>
            {(companies ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        )}
        {!isVendorUser && (
          <MultiSelect label="Contractors" width="w-56" allLabel="All contractors"
            options={(vendorOpts ?? []).map((v) => ({ id: v.id, name: v.name }))}
            value={vendorIds} onChange={setVendorIds} />
        )}
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={15} />
          <input className="input pl-9 w-56" placeholder="Search worker or code…"
            value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        {isFetching && <Loader2 size={15} className="animate-spin text-gray-400" />}
        <span className="text-sm text-gray-500 ml-auto">
          {priced} of {rows.length} priced
        </span>
      </div>

      {rows.length > priced && (
        <div className="card !py-3 border-l-4 border-amber-400 text-sm text-amber-800 flex items-center gap-2">
          <AlertTriangle size={15} />
          {rows.length - priced} worker(s) have no rate — they will show ₹0 on the wage register
          until one is set.
        </div>
      )}

      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-4 py-2.5 font-medium text-gray-500">Worker</th>
                <th className="text-left px-3 py-2.5 font-medium text-gray-500">Contractor</th>
                <th className="text-left px-3 py-2.5 font-medium text-gray-500">Grade</th>
                <th className="text-left px-3 py-2.5 font-medium text-gray-500">Paid</th>
                <th className="text-right px-3 py-2.5 font-medium text-gray-500">Rate (₹)</th>
                <th className="text-right px-3 py-2.5 font-medium text-gray-500">Day rate</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {rows.map((w) => {
                const { type, rate } = rowFor(w);
                const dayRate = type === "daily"
                  ? Number(rate) || 0
                  : Math.round(((Number(rate) || 0) / (w.wage_divisor || 26)) * 100) / 100;
                const dirty = !!edits[w.id];
                return (
                  <tr key={w.id} className={dirty ? "bg-brand-50/40" : "hover:bg-gray-50/60"}>
                    <td className="px-4 py-2">
                      <p className="font-medium text-gray-900 leading-tight">{w.name}</p>
                      {w.emp_code && <p className="text-[11px] text-gray-400">#{w.emp_code}</p>}
                    </td>
                    <td className="px-3 text-gray-600">{w.vendor ?? "—"}</td>
                    <td className="px-3 text-gray-500 text-xs">
                      {(w.skill_category ?? "").replace("_", "-") || "—"}
                    </td>
                    <td className="px-3 py-2">
                      <select className="input py-1 text-sm w-28" value={type}
                        onChange={(e) => setRow(w, { wage_type: e.target.value })}>
                        <option value="daily">Per day</option>
                        <option value="monthly">Per month</option>
                      </select>
                    </td>
                    <td className="px-3 py-2 text-right">
                      <input type="number" min="0" step={type === "daily" ? 10 : 100}
                        className={`input py-1 text-sm text-right w-28 ${
                          !Number(rate) ? "border-amber-400 bg-amber-50" : ""}`}
                        value={rate} placeholder="set rate"
                        onChange={(e) => setRow(w, { rate: e.target.value })} />
                    </td>
                    <td className="px-3 text-right text-gray-600">{dayRate ? money(dayRate) : "—"}</td>
                  </tr>
                );
              })}
              {rows.length === 0 && (
                <tr><td colSpan={6} className="text-center py-10 text-gray-400">
                  {ready ? "No workers in this selection." : "Select a company to load workers."}
                </td></tr>
              )}
            </tbody>
          </table>
        </div>

        {Object.keys(edits).length > 0 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100 bg-brand-50/50">
            <p className="text-sm text-gray-700">{Object.keys(edits).length} rate(s) changed</p>
            <div className="flex gap-2">
              <button className="btn-secondary text-sm" onClick={() => setEdits({})}>Cancel</button>
              <button className="btn-primary text-sm" disabled={save.isPending} onClick={() => save.mutate()}>
                <Save size={14} /> Save rates
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
