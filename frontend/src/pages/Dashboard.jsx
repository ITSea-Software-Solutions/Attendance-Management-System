import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import {
  Users, Building2, UserCheck, Clock, AlertTriangle, CheckCircle, TrendingUp,
  TrendingDown, Fingerprint, Download, Printer, ArrowRight, Activity, Camera,
  CalendarDays, LogIn, LogOut, Minus, FileSpreadsheet,
} from "lucide-react";
import { AreaChart, BarChart, Donut, HourlyFlow, PresenceBars } from "@/components/charts";

/* ── small pieces ─────────────────────────────────────────────────────────── */

function Delta({ now, before }) {
  const diff = (now ?? 0) - (before ?? 0);
  if (!before && !now) return null;
  const Icon = diff > 0 ? TrendingUp : diff < 0 ? TrendingDown : Minus;
  const cls = diff > 0 ? "text-emerald-600 bg-emerald-50" : diff < 0 ? "text-red-500 bg-red-50" : "text-gray-400 bg-gray-50";
  return (
    <span className={`inline-flex items-center gap-1 text-[11px] font-semibold px-1.5 py-0.5 rounded-md ${cls}`}
      title="vs yesterday">
      <Icon size={11} /> {diff > 0 ? `+${diff}` : diff} vs yday
    </span>
  );
}

function Kpi({ icon: Icon, label, value, tone = "blue", sub, extra, to }) {
  const tones = {
    blue:    "from-blue-500 to-blue-600",
    green:   "from-emerald-500 to-emerald-600",
    purple:  "from-violet-500 to-violet-600",
    amber:   "from-amber-400 to-amber-500",
    red:     "from-red-500 to-red-600",
    slate:   "from-slate-500 to-slate-600",
  };
  const body = (
    <div className="card flex items-start gap-3.5 hover:shadow-md transition-shadow h-full">
      <div className={`p-2.5 rounded-xl bg-gradient-to-br ${tones[tone]} text-white shadow-sm shrink-0`}>
        <Icon size={20} />
      </div>
      <div className="min-w-0">
        <p className="text-[13px] text-gray-500 truncate">{label}</p>
        <div className="flex items-center gap-2 flex-wrap">
          <p className="text-[26px] leading-8 font-bold text-gray-900">{value ?? "—"}</p>
          {extra}
        </div>
        {sub && <p className="text-xs text-gray-400 mt-0.5 truncate">{sub}</p>}
      </div>
    </div>
  );
  return to ? <Link to={to} className="block h-full">{body}</Link> : body;
}

function ComparePill({ title, now, before, unit = "worker-days" }) {
  const pct = before > 0 ? Math.round(((now - before) / before) * 100) : null;
  const up = (now ?? 0) >= (before ?? 0);
  return (
    <div className="flex-1 min-w-[150px] rounded-xl border border-gray-100 bg-gray-50/60 px-4 py-3">
      <p className="text-xs text-gray-500">{title}</p>
      <div className="flex items-baseline gap-2 mt-0.5">
        <span className="text-xl font-bold text-gray-900">{now ?? 0}</span>
        <span className="text-xs text-gray-400">{unit}</span>
      </div>
      <p className={`text-xs font-medium mt-0.5 ${up ? "text-emerald-600" : "text-red-500"}`}>
        {pct === null ? `prev ${before ?? 0}` : `${pct >= 0 ? "+" : ""}${pct}% vs prev (${before})`}
      </p>
    </div>
  );
}

const METHOD_BADGE = {
  fingerprint: { label: "thumb", cls: "bg-blue-50 text-blue-600" },
  face:        { label: "face",  cls: "bg-violet-50 text-violet-600" },
  manual:      { label: "manual", cls: "bg-amber-50 text-amber-600" },
};

/* ── page ─────────────────────────────────────────────────────────────────── */

export default function Dashboard() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [range, setRange] = useState("month"); // month | week
  const [reportMonth, setReportMonth] = useState(new Date().toISOString().slice(0, 7));

  const { data: ov, isLoading } = useQuery({
    queryKey: ["dashboard-overview"],
    queryFn:  () => api.get("/dashboard/overview").then((r) => r.data),
    refetchInterval: 60_000,
  });

  const role = user?.role;
  const isSuper   = role === "super_admin";
  const isCompany = ["company_admin", "company_hr", "company_gate"].includes(role);
  const isVendor  = ["vendor_admin", "vendor_operator"].includes(role);

  const downloadCsv = async (type) => {
    const r = await api.get("/attendance/export", { params: { month: reportMonth, type }, responseType: "blob" });
    const url = URL.createObjectURL(r.data);
    const a = document.createElement("a");
    a.href = url; a.download = `truecrew-attendance-${reportMonth}-${type}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  };
  const openPrintable = async () => {
    const r = await api.get("/attendance/printable", { params: { month: reportMonth }, responseType: "blob" });
    window.open(URL.createObjectURL(new Blob([r.data], { type: "text/html" })), "_blank");
  };

  if (isLoading || !ov) {
    return (
      <div className="space-y-5">
        <div className="h-24 card animate-pulse bg-gray-100" />
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[...Array(4)].map((_, i) => <div key={i} className="card animate-pulse h-24 bg-gray-100" />)}
        </div>
        <div className="grid lg:grid-cols-2 gap-4">
          <div className="card animate-pulse h-56 bg-gray-100" />
          <div className="card animate-pulse h-56 bg-gray-100" />
        </div>
      </div>
    );
  }

  const k = ov.kpis || {};
  const hour = new Date().getHours();
  const greeting = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";
  const firstName = (user?.name || "").split(" ")[0];

  // Trend windows
  const trendData = (range === "week" ? ov.trend.slice(-7) : ov.trend).map((p) => ({ d: p.d, value: p.present }));
  const weekBars = ov.trend.slice(-7).map((p, i, arr) => ({
    label: new Date(p.d + "T00:00:00").toLocaleDateString("en-IN", { weekday: "short" }),
    value: p.present,
    hot: i === arr.length - 1,
  }));

  // Quick actions per role
  const actions = isSuper ? [
    { label: "Subscriptions", to: "/subscriptions", icon: Building2 },
    { label: "Live Board", to: "/live", icon: Activity },
    { label: "Workers", to: "/workers", icon: Users },
  ] : isCompany ? [
    ...(role !== "company_hr" ? [{ label: "Mark Attendance", to: "/attendance/mark", icon: Fingerprint }] : []),
    { label: "Live Board", to: "/live", icon: Activity },
    { label: "Approvals", to: "/workers/assign", icon: CheckCircle },
    { label: "Visitors", to: "/visitors", icon: Camera },
  ] : [
    { label: "Register Worker", to: "/workers/register", icon: Users },
    { label: "Deploy Workers", to: "/workers/assign", icon: ArrowRight },
    { label: "Attendance", to: "/attendance", icon: CalendarDays },
  ];

  return (
    <div className="space-y-5">
      {/* ── Hero ── */}
      <div className="rounded-2xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white px-6 py-5 flex flex-wrap items-center gap-4 shadow-md">
        <div className="min-w-0 flex-1">
          <p className="text-slate-300 text-sm">
            {new Date().toLocaleDateString("en-IN", { weekday: "long", day: "numeric", month: "long", year: "numeric" })}
          </p>
          <h1 className="text-2xl font-bold mt-0.5 truncate">{greeting}{firstName ? `, ${firstName}` : ""} 👋</h1>
          <p className="text-slate-300 text-sm mt-1">
            <b className="text-white text-base">{ov.present_today}</b> worker{ov.present_today === 1 ? "" : "s"} present today
            {k.deployed_today > 0 && <> · <b className="text-white">{k.deployed_today}</b> deployed</>}
            {typeof k.still_inside === "number" && k.still_inside > 0 && <> · <b className="text-amber-300">{k.still_inside}</b> still inside</>}
          </p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {actions.map((a) => (
            <Link key={a.to} to={a.to}
              className="inline-flex items-center gap-1.5 rounded-lg bg-white/10 hover:bg-white/20 transition-colors px-3 py-2 text-sm font-medium backdrop-blur">
              <a.icon size={15} /> {a.label}
            </Link>
          ))}
        </div>
      </div>

      {/* ── KPI grid ── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {isSuper && <>
          <Kpi icon={UserCheck} label="Present today" value={k.present_today} tone="green"
            extra={<Delta now={ov.present_today} before={ov.present_yesterday} />} to="/attendance" />
          <Kpi icon={Building2} label="Companies" value={k.companies} tone="blue" to="/companies" />
          <Kpi icon={Users} label="Vendors" value={k.vendors} tone="purple" to="/vendors" />
          <Kpi icon={Fingerprint} label="Active workers" value={k.workers_active} tone="slate" to="/workers" />
        </>}
        {isCompany && <>
          <Kpi icon={UserCheck} label="Present today" value={k.present_today} tone="green"
            extra={<Delta now={ov.present_today} before={ov.present_yesterday} />} to="/attendance" />
          <Kpi icon={Clock} label="Deployed today" value={k.deployed_today} tone="blue"
            sub={k.deployed_today > 0 ? `${Math.min(100, Math.round(((k.present_today || 0) / k.deployed_today) * 100))}% arrived` : undefined}
            to="/workers" />
          <Kpi icon={AlertTriangle} label="Still inside" value={k.still_inside} tone={k.still_inside > 0 ? "amber" : "slate"}
            to="/attendance/exceptions" />
          <Kpi icon={Users} label="Approved vendors" value={k.vendors} tone="purple" to="/vendors" />
        </>}
        {isVendor && <>
          <Kpi icon={UserCheck} label="Present today" value={k.present_today} tone="green"
            extra={<Delta now={ov.present_today} before={ov.present_yesterday} />} to="/attendance" />
          <Kpi icon={Clock} label="Deployed today" value={k.deployed_today} tone="blue" to="/workers/assign" />
          <Kpi icon={Users} label="Workers" value={k.workers_total} tone="purple"
            sub={`${k.workers_active ?? 0} active`} to="/workers" />
          <Kpi icon={Building2} label="Client companies" value={k.companies} tone="slate" to="/vendors/company-access" />
        </>}
      </div>

      {/* ── Attention strip ── */}
      {ov.attention?.length > 0 && (
        <div className="card !py-3.5">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-800 mr-1">
              <AlertTriangle size={15} className="text-amber-500" /> Needs attention
            </span>
            {ov.attention.map((a) => (
              <button key={a.label} onClick={() => navigate(a.to)}
                className="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 hover:bg-amber-100 transition-colors px-3 py-1.5 text-[13px] font-medium text-amber-800">
                <b>{a.count}</b> {a.label} <ArrowRight size={12} />
              </button>
            ))}
          </div>
        </div>
      )}

      {/* ── Trend + week bars ── */}
      <div className="grid lg:grid-cols-5 gap-4">
        <div className="card lg:col-span-3">
          <div className="flex items-center justify-between mb-1">
            <div>
              <h2 className="font-semibold text-gray-900">Attendance trend</h2>
              <p className="text-xs text-gray-400">Unique workers present per day</p>
            </div>
            <div className="flex rounded-lg bg-gray-100 p-0.5 text-[13px] font-medium">
              {["week", "month"].map((r) => (
                <button key={r} onClick={() => setRange(r)}
                  className={`px-3 py-1 rounded-md capitalize transition-colors ${range === r ? "bg-white shadow text-gray-900" : "text-gray-500"}`}>
                  {r === "week" ? "7 days" : "30 days"}
                </button>
              ))}
            </div>
          </div>
          <AreaChart data={trendData} label="attendance-trend" />
          <div className="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-50">
            <ComparePill title="This week" now={ov.week_compare?.this_week} before={ov.week_compare?.last_week} />
            <ComparePill title="This month" now={ov.month_compare?.this_month} before={ov.month_compare?.last_month} />
          </div>
        </div>

        <div className="card lg:col-span-2">
          <h2 className="font-semibold text-gray-900">This week</h2>
          <p className="text-xs text-gray-400 mb-1">Workers present by day</p>
          <BarChart data={weekBars} color="#10b981" />
          <div className="mt-2 pt-3 border-t border-gray-50">
            <p className="text-xs text-gray-400 mb-1">Today's IN flow by hour</p>
            <HourlyFlow values={ov.hourly} />
          </div>
        </div>
      </div>

      {/* ── Donut + breakdown + recent ── */}
      <div className="grid lg:grid-cols-3 gap-4">
        <div className="card">
          <h2 className="font-semibold text-gray-900 mb-3">
            {isSuper ? "Organisations by plan" : isVendor ? "Your workforce" : "Today's turnout"}
          </h2>
          <Donut segments={ov.donut} centerLabel={isSuper ? "orgs" : "workers"} />
        </div>

        <div className="card">
          <h2 className="font-semibold text-gray-900 mb-3">
            {isVendor ? "Presence by company" : isSuper ? "Presence by company" : "Presence by vendor"}
            <span className="text-xs font-normal text-gray-400 ml-2">today</span>
          </h2>
          <PresenceBars rows={ov.breakdown} />
        </div>

        <div className="card">
          <div className="flex items-center justify-between mb-3">
            <h2 className="font-semibold text-gray-900">Latest marks</h2>
            <Link to="/attendance" className="text-xs font-medium text-blue-600 hover:underline inline-flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          </div>
          {ov.recent?.length ? (
            <div className="space-y-1">
              {ov.recent.map((l) => {
                const m = METHOD_BADGE[l.method] || null;
                return (
                  <div key={l.id}
                    className="flex items-center gap-2.5 py-1.5 px-1 rounded-lg hover:bg-gray-50 cursor-pointer"
                    onClick={() => l.worker_id && navigate(`/workers/${l.worker_id}`)}>
                    <span className={`inline-flex items-center justify-center w-7 h-7 rounded-lg shrink-0 ${l.type === "IN" ? "bg-emerald-50 text-emerald-600" : "bg-blue-50 text-blue-600"}`}>
                      {l.type === "IN" ? <LogIn size={14} /> : <LogOut size={14} />}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-medium text-gray-800 truncate">{l.worker || "—"}</p>
                      <p className="text-[11px] text-gray-400 truncate">{l.gate || "Main Gate"}</p>
                    </div>
                    {m && <span className={`text-[10.5px] font-semibold px-1.5 py-0.5 rounded ${m.cls}`}>{m.label}</span>}
                    <span className="text-xs text-gray-500 font-medium shrink-0">{l.time}</span>
                  </div>
                );
              })}
            </div>
          ) : (
            <p className="text-sm text-gray-400 py-6 text-center">No attendance marked yet today</p>
          )}
        </div>
      </div>

      {/* ── Reports ── */}
      <div className="card">
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2 mr-2">
            <span className="p-2 rounded-lg bg-blue-50 text-blue-600"><FileSpreadsheet size={17} /></span>
            <div>
              <h2 className="font-semibold text-gray-900 leading-tight">Reports</h2>
              <p className="text-xs text-gray-400">Attendance exports for any month</p>
            </div>
          </div>
          <input type="month" className="input w-44 py-1.5 text-sm" value={reportMonth}
            onChange={(e) => setReportMonth(e.target.value)} />
          <button className="btn-secondary text-sm" onClick={() => downloadCsv("daily")}>
            <Download size={14} /> Daily CSV
          </button>
          <button className="btn-secondary text-sm" onClick={() => downloadCsv("monthly")}>
            <Download size={14} /> Monthly totals
          </button>
          <button className="btn-secondary text-sm" onClick={openPrintable}>
            <Printer size={14} /> Printable report
          </button>
          <Link to="/reports" className="text-sm font-medium text-blue-600 hover:underline inline-flex items-center gap-1 ml-auto">
            All reports & filters <ArrowRight size={13} />
          </Link>
        </div>
      </div>
    </div>
  );
}
