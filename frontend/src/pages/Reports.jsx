import { useMemo, useState } from "react";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import toast from "react-hot-toast";
import PageHint from "@/components/PageHint";
import {
  FileSpreadsheet, Download, Printer, Play, CalendarDays, Users, Building2,
  AlertTriangle, Search, X, Loader2, Table as TableIcon, BarChart3, Clock3,
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
    id: "daily", icon: CalendarDays, needsRange: true,
    label: "Daily register",
    desc: "One row per worker per day — first IN, last OUT, hours, gate",
  },
  {
    id: "hours", icon: Clock3, needsRange: true, hasGroups: true,
    label: "Hours & wage days",
    desc: "Hours worked converted to payable days — 8h = 1 day, 4h = half day",
  },
  {
    id: "monthly", icon: TableIcon, needsRange: true,
    label: "Attendance totals",
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
const FILTERABLE = ["Vendor", "Company", "Location(s)", "Status", "Gate", "Day type", "Week", "Month"];

// How the hours report is rolled up
const HOUR_GROUPS = [
  { id: "daily",   label: "Day by day" },
  { id: "weekly",  label: "Weekly" },
  { id: "monthly", label: "Monthly" },
  { id: "summary", label: "Total per worker" },
];

/** Quick date-range presets — the pay periods people actually ask for. */
const iso = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
function presetRange(id) {
  const now = new Date();
  const day = (now.getDay() + 6) % 7; // Monday = 0
  const monStart = new Date(now); monStart.setDate(now.getDate() - day);
  switch (id) {
    case "this_week":  return [iso(monStart), iso(now)];
    case "last_week": {
      const s = new Date(monStart); s.setDate(s.getDate() - 7);
      const e = new Date(monStart); e.setDate(e.getDate() - 1);
      return [iso(s), iso(e)];
    }
    case "last_month": {
      const s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const e = new Date(now.getFullYear(), now.getMonth(), 0);
      return [iso(s), iso(e)];
    }
    case "last_30":    { const s = new Date(now); s.setDate(s.getDate() - 29); return [iso(s), iso(now)]; }
    default:           return [iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)];
  }
}
const PRESETS = [
  { id: "this_month", label: "This month" },
  { id: "last_month", label: "Last month" },
  { id: "this_week",  label: "This week" },
  { id: "last_week",  label: "Last week" },
  { id: "last_30",    label: "Last 30 days" },
];

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
  const [preset, setPreset] = useState("this_month");
  const [[from, to], setRange] = useState(() => presetRange("this_month"));
  const [hoursGroup, setHoursGroup] = useState("summary");
  const [date, setDate] = useState(iso(new Date()));
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
    const all = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: false });
    // Some exports open with a note line (e.g. the wage rule) before the real
    // header — the header is the first row carrying two or more cells.
    const hi = all.findIndex((r) => (r || []).filter((c) => String(c ?? "").trim() !== "").length > 1);
    const note = hi > 0
      ? all.slice(0, hi).map((r) => String(r?.[0] ?? "").trim()).filter(Boolean).join(" ")
      : null;
    const rows = all.slice(Math.max(hi, 0));
    const headers = (rows[0] || []).map((h) => String(h).replace(/^﻿/, ""));
    // Server CSVs prefix formula-leading cells with ' so Excel treats them as
    // text — strip it for display, charts and the re-generated download.
    const clean = (c) => (typeof c === "string" ? c.replace(/^'(?=[=+\-@])/, "") : c);
    return {
      headers,
      note,
      rows: rows.slice(1)
        .filter((r) => r.some((c) => String(c ?? "").trim() !== ""))
        .map((r) => r.map(clean)),
    };
  };

  const run = async () => {
    setLoading(true);
    setFilters({}); setSearch("");
    try {
      let headers = [], rows = [], note = null, label = def.label;
      const periodLabel = `${from} → ${to}`;
      if (reportId === "daily" || reportId === "monthly") {
        const r = await api.get("/attendance/export", {
          params: { from, to, type: reportId }, responseType: "blob",
        });
        ({ headers, rows, note } = await parseCsvBlob(r.data));
        label = `${def.label} — ${periodLabel}`;
      } else if (reportId === "hours") {
        const r = await api.get("/attendance/hours-report", {
          params: { from, to, group: hoursGroup }, responseType: "blob",
        });
        ({ headers, rows, note } = await parseCsvBlob(r.data));
        label = `${def.label} · ${HOUR_GROUPS.find((g) => g.id === hoursGroup).label} — ${periodLabel}`;
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
      setLoaded({ id: reportId, headers, rows, note, label,
                  group: reportId === "hours" ? hoursGroup : null });
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
    const hoursCol = ["Hours", "Total hours", "Total Hours"].map(idx).find((i) => i >= 0) ?? -1;
    if (hoursCol >= 0) {
      let mins = 0;
      for (const r of view) {
        const m = String(r[hoursCol] ?? "").match(/^(\d+):(\d{2})$/);
        if (m) mins += (+m[1]) * 60 + (+m[2]);
      }
      out.push({ label: "Total hours", value: `${Math.floor(mins / 60)}:${String(mins % 60).padStart(2, "0")}` });
    }
    // Wage days: "Payable days" on rolled-up reports, "Day value" day by day.
    const payCol = idx("Payable days") >= 0 ? idx("Payable days") : idx("Day value");
    if (payCol >= 0) {
      const days = view.reduce((sum, r) => sum + (parseFloat(r[payCol]) || 0), 0);
      out.push({ label: "Payable days", value: Math.round(days * 10) / 10 });
    }
    const otCol = idx("Overtime");
    if (otCol >= 0) {
      const ot = view.reduce((sum, r) => sum + hmToMins(r[otCol]), 0);
      if (ot > 0) out.push({ label: "Overtime", value: minsToHM(ot) });
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

    if (loaded.id === "hours") {
      const wi = idx("Worker"), payI = idx("Payable days"), hrI = idx("Total hours");
      const periodI = idx("Week") >= 0 ? idx("Week") : idx("Month") >= 0 ? idx("Month") : idx("Date");

      if (loaded.group === "daily") {
        const dI = idx("Date"), dvI = idx("Day value"), dhI = idx("Hours"), dtI = idx("Day type");
        if (dI >= 0 && dvI >= 0) {
          const byDate = {};
          for (const r of view) byDate[r[dI]] = (byDate[r[dI]] || 0) + (parseFloat(r[dvI]) || 0);
          out.push({ kind: "area", title: "Payable days per day", span: 2,
            data: Object.keys(byDate).sort().map((d) => ({ d, value: Math.round(byDate[d] * 10) / 10 })) });
        }
        const dt = donutOf(view, dtI);
        if (dt.length) out.push({ kind: "donut", title: "Full / half / short days", data: dt, center: "days" });
        if (wi >= 0 && dhI >= 0)
          out.push({ kind: "rank", title: "Top workers by hours", color: "#8b5cf6",
            data: rankOf(view, wi, { metric: (r) => hmToMins(r[dhI]), display: minsToHM }) });
      } else {
        if (periodI >= 0 && payI >= 0 && loaded.group !== "summary") {
          const byPeriod = {};
          for (const r of view) byPeriod[r[periodI]] = (byPeriod[r[periodI]] || 0) + (parseFloat(r[payI]) || 0);
          out.push({ kind: "bar", title: "Payable days per period", span: 2, color: "#10b981",
            data: Object.entries(byPeriod).map(([label, value]) => ({
              label: label.length > 12 ? label.slice(0, 12) : label,
              value: Math.round(value * 10) / 10,
            })) });
        }
        if (wi >= 0 && payI >= 0)
          out.push({ kind: "rank", title: "Top workers by payable days",
            data: rankOf(view, wi, { metric: (r) => parseFloat(r[payI]) || 0,
              display: (v) => `${Math.round(v * 10) / 10} days` }) });
        if (wi >= 0 && hrI >= 0)
          out.push({ kind: "rank", title: "Top workers by hours", color: "#8b5cf6",
            data: rankOf(view, wi, { metric: (r) => hmToMins(r[hrI]), display: minsToHM }) });
        const fullI = idx("Full days"), halfI = idx("Half days"), shortI = idx("Short days");
        if (fullI >= 0) {
          const tot = (i) => view.reduce((sum, r) => sum + (parseInt(r[i], 10) || 0), 0);
          const segs = [
            { label: "Full days", value: tot(fullI) },
            { label: "Half days", value: tot(halfI) },
            { label: "Short days", value: tot(shortI) },
          ].filter((x) => x.value > 0);
          if (segs.length) out.push({ kind: "donut", title: "Day types worked", data: segs, center: "days" });
        }
        const vd = donutOf(view, idx("Vendor"), { weight: (r) => (payI >= 0 ? parseFloat(r[payI]) || 0 : 1) });
        if (vd.length > 1) out.push({ kind: "donut", title: "Payable days by vendor", data: vd, center: "days" });
      }
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
    const r = await api.get("/attendance/printable", { params: { from, to }, responseType: "blob" });
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
        {def?.needsRange && (
          <>
            <div className="flex items-center gap-1 flex-wrap">
              {PRESETS.map((p) => (
                <button key={p.id}
                  onClick={() => { setPreset(p.id); setRange(presetRange(p.id)); }}
                  className={`rounded-lg px-2.5 py-1 text-[12.5px] font-medium border transition-colors ${
                    preset === p.id
                      ? "bg-blue-50 text-blue-700 border-blue-200"
                      : "bg-white text-gray-500 border-gray-200 hover:border-gray-300"}`}>
                  {p.label}
                </button>
              ))}
            </div>
            <label className="flex items-center gap-2 text-sm text-gray-600">
              <input type="date" className="input w-36 py-1.5 text-sm" value={from} max={to}
                onChange={(e) => { setPreset("custom"); setRange([e.target.value, to]); }} />
              <span className="text-gray-400">to</span>
              <input type="date" className="input w-36 py-1.5 text-sm" value={to} min={from}
                onChange={(e) => { setPreset("custom"); setRange([from, e.target.value]); }} />
            </label>
          </>
        )}
        {def?.hasGroups && (
          <div className="flex items-center gap-1 flex-wrap">
            <span className="text-sm text-gray-600 mr-1">Roll up:</span>
            {HOUR_GROUPS.map((g) => (
              <button key={g.id} onClick={() => setHoursGroup(g.id)}
                className={`rounded-lg px-2.5 py-1 text-[12.5px] font-medium border transition-colors ${
                  hoursGroup === g.id
                    ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                    : "bg-white text-gray-500 border-gray-200 hover:border-gray-300"}`}>
                {g.label}
              </button>
            ))}
          </div>
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

          {loaded.note && (
            <p className="text-[12.5px] text-gray-600 bg-amber-50/60 border border-amber-100 rounded-lg px-3 py-2 mb-4">
              {loaded.note}
            </p>
          )}

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
