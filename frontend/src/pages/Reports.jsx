import { useMemo, useState } from "react";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import toast from "react-hot-toast";
import PageHint from "@/components/PageHint";
import {
  FileSpreadsheet, Download, Printer, Play, CalendarDays, Users, Building2,
  AlertTriangle, Search, X, Loader2, Table as TableIcon,
} from "lucide-react";

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
