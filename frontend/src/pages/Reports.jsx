import { useMemo, useState } from "react";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import toast from "react-hot-toast";
import PageHint from "@/components/PageHint";
import {
  FileSpreadsheet, Download, Printer, Play, CalendarDays, Users, Building2,
  AlertTriangle, Search, X, Loader2, Table as TableIcon, BarChart3,
} from "lucide-react";
import { AreaChart, BarChart, Donut, HBarList } from "@/components/charts";

/**
 * Reports — one place for every export, with filters and an on-screen
 * preview BEFORE downloading. The report loads once from the server
 * (already role-scoped), then vendor / company / gate / search filters
 * apply instantly on the client; Excel & CSV download exactly what the
 * preview shows.
 */

const REPORT_DEFS = [
  {
    id: "daily", icon: CalendarDays, needsMonth: true,
    label: "Daily register",
    desc: "One row per worker per day — first IN, last OUT, hours, gate",
  },
  {
    id: "monthly", icon: TableIcon, needsMonth: true,
    label: "Monthly totals",
    desc: "Days present, total hours and missing OUTs per worker",
  },
  {
    id: "inside", icon: AlertTriangle, needsDate: true,
    label: "Still inside",
    desc: "Workers checked IN without an OUT on the chosen day",
  },
  {
    id: "workers", icon: Users,
    label: "Workers directory",
    desc: "Full registry — emp codes, Aadhaar status, PAN, contacts",
  },
  {
    id: "vendors", icon: Building2, companyOnly: true,
    label: "Vendors directory",
    desc: "Your contractors with contact and status details",
  },
];

// Columns that get a dropdown filter when present in the report
const FILTERABLE = ["Vendor", "Company", "Location(s)", "Status", "Gate"];

function csvEscape(v) {
  const s = String(v ?? "");
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

/* chart helpers */
const hmToMins = (v) => {
  const m = String(v ?? "").match(/^(\d+):(\d{2})$/);
  return m ? (+m[1]) * 60 + (+m[2]) : 0;
};
const minsToHM = (mins) => `${Math.floor(mins / 60)}:${String(mins % 60).padStart(2, "0")}`;
const hourOf = (v) => {
  const m = String(v ?? "").match(/\b(\d{2}):\d{2}/);
  return m ? +m[1] : null;
};
const hourLabel = (h) => (h === 0 ? "12am" : h < 12 ? `${h}am` : h === 12 ? "12pm" : `${h - 12}pm`);

/** Count rows per value of a column → donut segments (top N + Other). */
function donutOf(view, idx, { top = 5, map, weight } = {}) {
  if (idx < 0) return [];
  const counts = {};
  for (const r of view) {
    let key = String(r[idx] ?? "").trim() || "—";
    if (map) key = map(key);
    counts[key] = (counts[key] || 0) + (weight ? weight(r) : 1);
  }
  const sorted = Object.entries(counts).sort((a, b) => b[1] - a[1]);
  const segs = sorted.slice(0, top).map(([label, value]) => ({ label, value }));
  const rest = sorted.slice(top).reduce((s, [, v]) => s + v, 0);
  if (rest > 0) segs.push({ label: "Other", value: rest });
  return segs;
}

/** Sum a numeric metric per value of a column → ranked bars (top N). */
function rankOf(view, keyIdx, { top = 6, metric, display } = {}) {
  if (keyIdx < 0) return [];
  const agg = {};
  for (const r of view) {
    const key = String(r[keyIdx] ?? "").trim() || "—";
    agg[key] = (agg[key] || 0) + (metric ? metric(r) : 1);
  }
  return Object.entries(agg).sort((a, b) => b[1] - a[1]).slice(0, top)
    .map(([label, value]) => ({ label, value, display: display ? display(value) : value }));
}

export default function Reports() {
  const { user } = useAuth();
  const isVendorUser = ["vendor_admin", "vendor_operator"].includes(user?.role);

  const [reportId, setReportId] = useState("daily");
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [loading, setLoading] = useState(false);
  const [loaded, setLoaded] = useState(null); // {id, headers, rows, label, month?}
  const [filters, setFilters] = useState({}); // {colIndex: value}
  const [search, setSearch] = useState("");
  const [showCharts, setShowCharts] = useState(true);

  const def = REPORT_DEFS.find((r) => r.id === reportId);
  const reports = REPORT_DEFS.filter((r) => !(r.companyOnly && isVendorUser));

  /* ── loading ── */

  const parseCsvBlob = async (blob) => {
    const XLSX = await import("xlsx");
    const wb = XLSX.read(await blob.text(), { type: "string", raw: true });
    const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: false });
    const headers = (rows[0] || []).map((h) => String(h).replace(/^﻿/, ""));
    return { headers, rows: rows.slice(1).filter((r) => r.some((c) => String(c ?? "").trim() !== "")) };
  };

  const run = async () => {
    setLoading(true);
    setFilters({}); setSearch("");
    try {
      let headers = [], rows = [], label = def.label;
      if (reportId === "daily" || reportId === "monthly") {
        const r = await api.get("/attendance/export", {
          params: { month, type: reportId }, responseType: "blob",
        });
        ({ headers, rows } = await parseCsvBlob(r.data));
        label = `${def.label} — ${month}`;
      } else if (reportId === "workers") {
        const r = await api.get("/workers-export", { responseType: "blob" });
        ({ headers, rows } = await parseCsvBlob(r.data));
      } else if (reportId === "vendors") {
        const r = await api.get("/vendors-export", { responseType: "blob" });
        ({ headers, rows } = await parseCsvBlob(r.data));
      } else if (reportId === "inside") {
        const r = await api.get("/attendance/exceptions", { params: { date } });
        headers = ["Worker", "Company", "Gate"];
        rows = (r.data?.missing_out || []).map((x) => [
          x.worker?.name ?? "—", x.company?.name ?? "—", x.location_name ?? "Main Gate",
        ]);
        label = `${def.label} — ${date}`;
      }
      setLoaded({ id: reportId, headers, rows, label, month: def.needsMonth ? month : null });
      if (!rows.length) toast("No rows for this selection.", { icon: "ℹ️" });
    } catch (e) {
      toast.error(e.response?.status === 403
        ? "This export is a Professional/Enterprise feature — see Plan & Billing."
        : "Could not load the report.");
    } finally {
      setLoading(false);
    }
  };

  /* ── client-side filtering ── */

  const filterCols = useMemo(() => {
    if (!loaded) return [];
    return loaded.headers
      .map((h, i) => ({ h, i }))
      .filter(({ h }) => FILTERABLE.includes(h))
      .map(({ h, i }) => ({
        h, i,
        options: [...new Set(loaded.rows.map((r) => String(r[i] ?? "").trim()).filter(Boolean))].sort(),
      }))
      .filter((c) => c.options.length > 1);
  }, [loaded]);

  const view = useMemo(() => {
    if (!loaded) return [];
    const q = search.trim().toLowerCase();
    return loaded.rows.filter((r) => {
      for (const [i, v] of Object.entries(filters)) {
        if (v && String(r[i] ?? "").trim() !== v) return false;
      }
      if (q && !r.some((c) => String(c ?? "").toLowerCase().includes(q))) return false;
      return true;
    });
  }, [loaded, filters, search]);

  /* ── summary chips ── */

  const summary = useMemo(() => {
    if (!loaded || !view.length) return [];
    const h = loaded.headers;
    const idx = (name) => h.indexOf(name);
    const out = [{ label: "Rows", value: view.length }];
    const wi = idx("Worker");
    if (wi >= 0) out.push({ label: "Workers", value: new Set(view.map((r) => r[wi])).size });
    const hoursCol = idx("Hours") >= 0 ? idx("Hours") : idx("Total Hours");
    if (hoursCol >= 0) {
      let mins = 0;
      for (const r of view) {
        const m = String(r[hoursCol] ?? "").match(/^(\d+):(\d{2})$/);
        if (m) mins += (+m[1]) * 60 + (+m[2]);
      }
      out.push({ label: "Total hours", value: `${Math.floor(mins / 60)}:${String(mins % 60).padStart(2, "0")}` });
    }
    const st = idx("Status");
    if (st >= 0) {
      const miss = view.filter((r) => r[st] === "Missing OUT").length;
      if (miss) out.push({ label: "Missing OUT", value: miss, warn: true });
    }
    const dm = idx("Days Missing OUT");
    if (dm >= 0) {
      const miss = view.reduce((s, r) => s + (+r[dm] || 0), 0);
      if (miss) out.push({ label: "Missing OUTs", value: miss, warn: true });
    }
    return out;
  }, [loaded, view]);

  /* ── insights: charts computed from the FILTERED view ── */

  const insights = useMemo(() => {
    if (!loaded || !view.length) return [];
    const h = loaded.headers;
    const idx = (n) => h.indexOf(n);
    const out = [];

    if (loaded.id === "daily") {
      const di = idx("Date"), wi = idx("Worker"), hi = idx("Hours"), fi = idx("First IN");
      if (di >= 0) {
        const byDate = {};
        for (const r of view) (byDate[r[di]] ??= new Set()).add(r[wi >= 0 ? wi : di]);
        const days = Object.keys(byDate).sort();
        out.push({ kind: "area", title: "Workers present per day", span: 2,
          data: days.map((d) => ({ d, value: byDate[d].size })) });
      }
      if (fi >= 0) {
        const hours = {};
        for (const r of view) { const hh = hourOf(r[fi]); if (hh !== null) hours[hh] = (hours[hh] || 0) + 1; }
        const ks = Object.keys(hours).map(Number).sort((a, b) => a - b);
        if (ks.length) {
          const bars = [];
          for (let hh = ks[0]; hh <= ks[ks.length - 1]; hh++)
            bars.push({ label: hourLabel(hh), value: hours[hh] || 0 });
          out.push({ kind: "bar", title: "Arrival time (first IN)", color: "#10b981", data: bars });
        }
      }
      const vd = donutOf(view, idx("Vendor"));
      if (vd.length > 1) out.push({ kind: "donut", title: "Worker-days by vendor", data: vd, center: "days" });
      if (wi >= 0 && hi >= 0)
        out.push({ kind: "rank", title: "Top workers by hours",
          data: rankOf(view, wi, { metric: (r) => hmToMins(r[hi]), display: minsToHM }) });
    }

    if (loaded.id === "monthly") {
      const wi = idx("Worker"), dp = idx("Days Present"), th = idx("Total Hours");
      if (wi >= 0 && dp >= 0)
        out.push({ kind: "rank", title: "Top workers by days present",
          data: rankOf(view, wi, { metric: (r) => +r[dp] || 0, display: (v) => `${v} days` }) });
      if (wi >= 0 && th >= 0)
        out.push({ kind: "rank", title: "Top workers by hours", color: "#8b5cf6",
          data: rankOf(view, wi, { metric: (r) => hmToMins(r[th]), display: minsToHM }) });
      const vd = donutOf(view, idx("Vendor"), { weight: (r) => (dp >= 0 ? +r[dp] || 0 : 1) });
      if (vd.length > 1) out.push({ kind: "donut", title: "Days by vendor", data: vd, center: "days" });
      const cd = donutOf(view, idx("Company"), { weight: (r) => (dp >= 0 ? +r[dp] || 0 : 1) });
      if (cd.length > 1) out.push({ kind: "donut", title: "Days by company", data: cd, center: "days" });
    }

    if (loaded.id === "inside") {
      const gd = donutOf(view, idx("Gate"));
      if (gd.length) out.push({ kind: "donut", title: "Still inside by gate", data: gd, center: "inside" });
      const cd = donutOf(view, idx("Company"));
      if (cd.length > 1) out.push({ kind: "donut", title: "By company", data: cd, center: "inside" });
    }

    if (loaded.id === "workers") {
      const sd = donutOf(view, idx("Status"), { map: (v) => v.charAt(0).toUpperCase() + v.slice(1) });
      if (sd.length) out.push({ kind: "donut", title: "Workers by status", data: sd, center: "workers" });
      const ad = donutOf(view, idx("Aadhaar verified"), { map: (v) => (v.toLowerCase().startsWith("yes") ? "Verified" : "Pending") });
      if (ad.length) out.push({ kind: "donut", title: "Aadhaar verification", data: ad, center: "workers" });
      const gd = donutOf(view, idx("Gender"), { map: (v) => ({ M: "Male", F: "Female", O: "Other" }[v] || "—") });
      if (gd.length > 1) out.push({ kind: "donut", title: "Gender split", data: gd, center: "workers" });
      const vr = rankOf(view, idx("Vendor"), { display: (v) => `${v}` });
      if (vr.length > 1) out.push({ kind: "rank", title: "Workers per vendor", color: "#f59e0b", data: vr });
    }

    if (loaded.id === "vendors") {
      const sd = donutOf(view, idx("Status"), { map: (v) => v.charAt(0).toUpperCase() + v.slice(1) });
      if (sd.length) out.push({ kind: "donut", title: "Vendors by status", data: sd, center: "vendors" });
      const pd = donutOf(view, idx("Plan"), { map: (v) => v.charAt(0).toUpperCase() + v.slice(1) });
      if (pd.length) out.push({ kind: "donut", title: "Vendors by plan", data: pd, center: "vendors" });
    }

    return out.filter((c) => c.data?.length);
  }, [loaded, view]);

  /* ── downloads: exactly what the preview shows ── */

  const baseName = () =>
    `truecrew-${loaded.id}${loaded.month ? `-${loaded.month}` : ""}-${new Date().toISOString().slice(0, 10)}`;

  const downloadCsv = () => {
    const lines = [loaded.headers, ...view].map((r) => r.map(csvEscape).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob(["﻿" + lines], { type: "text/csv;charset=utf-8" }));
    const a = document.createElement("a");
    a.href = url; a.download = `${baseName()}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  };

  const downloadExcel = async () => {
    const XLSX = await import("xlsx");
    const data = [loaded.headers, ...view];
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws["!cols"] = loaded.headers.map((_, c) => ({
      wch: Math.min(32, Math.max(10, ...data.map((row) => String(row?.[c] ?? "").length + 2))),
    }));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Report");
    XLSX.writeFile(wb, `${baseName()}.xlsx`);
  };

  const openPrintable = async () => {
    const r = await api.get("/attendance/printable", { params: { month: loaded.month }, responseType: "blob" });
    window.open(URL.createObjectURL(new Blob([r.data], { type: "text/html" })), "_blank");
  };

  const activeFilterCount = Object.values(filters).filter(Boolean).length + (search ? 1 : 0);

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Reports</h1>
        <p className="text-gray-500 text-sm mt-0.5">Run a report, filter it on screen, then download exactly what you see</p>
      </div>
      <PageHint id="reports">
        Pick a report and press <b>Run</b>. The preview appears below — use the dropdowns and
        search to narrow it, then <b>Excel</b> / <b>CSV</b> downloads the filtered view.
      </PageHint>

      {/* ── report picker ── */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
        {reports.map((r) => (
          <button key={r.id} onClick={() => setReportId(r.id)}
            className={`text-left rounded-xl border p-3.5 transition-all ${reportId === r.id
              ? "border-blue-500 bg-blue-50/60 shadow-sm ring-1 ring-blue-500"
              : "border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm"}`}>
            <r.icon size={18} className={reportId === r.id ? "text-blue-600" : "text-gray-400"} />
            <p className="font-semibold text-[13.5px] text-gray-900 mt-2 leading-tight">{r.label}</p>
            <p className="text-[11.5px] text-gray-500 mt-1 leading-snug">{r.desc}</p>
          </button>
        ))}
      </div>

      {/* ── run bar ── */}
      <div className="card !py-3.5 flex flex-wrap items-center gap-3">
        {def?.needsMonth && (
          <label className="flex items-center gap-2 text-sm text-gray-600">
            Month
            <input type="month" className="input w-44 py-1.5 text-sm" value={month}
              onChange={(e) => setMonth(e.target.value)} />
          </label>
        )}
        {def?.needsDate && (
          <label className="flex items-center gap-2 text-sm text-gray-600">
            Date
            <input type="date" className="input w-44 py-1.5 text-sm" value={date}
              onChange={(e) => setDate(e.target.value)} />
          </label>
        )}
        <button className="btn-primary text-sm" onClick={run} disabled={loading}>
          {loading ? <Loader2 size={14} className="animate-spin" /> : <Play size={14} />}
          Run report
        </button>

        {loaded && view.length > 0 && (
          <div className="flex items-center gap-2 ml-auto flex-wrap">
            <button className="btn-secondary text-sm" onClick={downloadExcel}>
              <FileSpreadsheet size={14} /> Excel
            </button>
            <button className="btn-secondary text-sm" onClick={downloadCsv}>
              <Download size={14} /> CSV
            </button>
            {(loaded.id === "daily" || loaded.id === "monthly") && (
              <button className="btn-secondary text-sm" onClick={openPrintable}>
                <Printer size={14} /> Printable
              </button>
            )}
          </div>
        )}
      </div>

      {/* ── results ── */}
      {loaded && (
        <div className="card">
          <div className="flex flex-wrap items-center gap-2.5 mb-4">
            <h2 className="font-semibold text-gray-900 mr-1">{loaded.label}</h2>
            {insights.length > 0 && (
              <button onClick={() => setShowCharts((v) => !v)}
                className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[12.5px] font-medium border transition-colors ${
                  showCharts ? "bg-blue-50 text-blue-700 border-blue-200" : "bg-white text-gray-500 border-gray-200 hover:border-gray-300"}`}>
                <BarChart3 size={13} /> Charts {showCharts ? "on" : "off"}
              </button>
            )}
            {summary.map((s) => (
              <span key={s.label}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12.5px] font-medium ${
                  s.warn ? "bg-amber-50 text-amber-700 border border-amber-200" : "bg-gray-100 text-gray-700"}`}>
                {s.label}: <b>{s.value}</b>
              </span>
            ))}
          </div>

          {/* filter row */}
          <div className="flex flex-wrap items-center gap-2.5 mb-4">
            <div className="relative">
              <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
              <input className="input !pl-8 w-52 py-1.5 text-sm" placeholder="Search rows…"
                value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
            {filterCols.map((c) => (
              <select key={c.i} className="input w-auto py-1.5 text-sm" value={filters[c.i] || ""}
                onChange={(e) => setFilters((f) => ({ ...f, [c.i]: e.target.value }))}>
                <option value="">{c.h}: all</option>
                {c.options.map((o) => <option key={o} value={o}>{o}</option>)}
              </select>
            ))}
            {activeFilterCount > 0 && (
              <button className="text-xs font-medium text-gray-500 hover:text-gray-800 inline-flex items-center gap-1"
                onClick={() => { setFilters({}); setSearch(""); }}>
                <X size={12} /> Clear filters
              </button>
            )}
            <span className="text-xs text-gray-400 ml-auto">
              {view.length !== loaded.rows.length ? `${view.length} of ${loaded.rows.length} rows` : `${view.length} rows`}
              {view.length > 100 && " · showing first 100"}
            </span>
          </div>

          {showCharts && insights.length > 0 && view.length > 0 && (
            <div className="grid md:grid-cols-2 gap-3 mb-5">
              {insights.map((c) => (
                <div key={c.title}
                  className={`rounded-xl border border-gray-100 bg-gray-50/50 p-4 ${c.span === 2 ? "md:col-span-2" : ""}`}>
                  <p className="text-[13px] font-semibold text-gray-700 mb-2">{c.title}</p>
                  {c.kind === "area" && <AreaChart data={c.data} height={150} label={c.title} />}
                  {c.kind === "bar" && <BarChart data={c.data} height={150} color={c.color} />}
                  {c.kind === "donut" && <Donut segments={c.data} size={116} centerLabel={c.center} />}
                  {c.kind === "rank" && <HBarList rows={c.data} color={c.color || "#2563eb"} />}
                </div>
              ))}
            </div>
          )}

          {view.length ? (
            <div className="overflow-x-auto -mx-2 px-2" style={{ maxHeight: "60vh", overflowY: "auto" }}>
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-gray-500 uppercase tracking-wide">
                    {loaded.headers.map((h) => <th key={h} className="py-2 pr-4 whitespace-nowrap">{h}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {view.slice(0, 100).map((r, i) => (
                    <tr key={i} className="border-t border-gray-50">
                      {loaded.headers.map((_, c) => (
                        <td key={c} className="py-1.5 pr-4 text-gray-700 whitespace-nowrap">{r[c] ?? ""}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-gray-400 py-8 text-center">
              {loaded.rows.length ? "No rows match the filters." : "No data for this selection."}
            </p>
          )}
        </div>
      )}

      {!loaded && !loading && (
        <div className="card text-center py-12 text-gray-400">
          <FileSpreadsheet size={34} className="mx-auto mb-3 opacity-40" />
          <p className="text-sm">Choose a report above and press <b>Run report</b>.</p>
        </div>
      )}
    </div>
  );
}
