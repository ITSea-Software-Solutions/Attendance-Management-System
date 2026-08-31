import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "@/lib/axios";
import AuthImg from "@/components/AuthImg";
import { useAuth } from "@/contexts/AuthContext";
import { format } from "date-fns";
import {
  Activity, LogIn, LogOut, Users, DoorOpen, Fingerprint, Camera,
  PenLine, MapPin, Clock,
} from "lucide-react";

const REFRESH_MS = 10000;

function MethodIcon({ method }) {
  if (method === "face") return <Camera size={11} className="inline" />;
  if (method === "manual") return <PenLine size={11} className="inline" />;
  return <Fingerprint size={11} className="inline" />;
}

/** Small overlapping avatar (worker photo or initial). */
function Avatar({ w, size = "w-9 h-9", ring = "ring-2 ring-white" }) {
  return (
    <div className={`${size} ${ring} rounded-full overflow-hidden bg-brand-100 flex items-center justify-center shrink-0`} title={w.name}>
      <AuthImg
        url={w.has_photo ? `/workers/${w.worker_id}/photo` : null}
        alt={w.name}
        className="w-full h-full object-cover"
        fallback={<span className="text-xs font-bold text-brand-700">{(w.name ?? "?")[0]?.toUpperCase()}</span>}
      />
    </div>
  );
}

function HeroTile({ label, value, sub, tone, pulse }) {
  return (
    <div className={`relative overflow-hidden rounded-2xl p-5 text-white shadow-lg ${tone}`}>
      <p className="text-[11px] uppercase tracking-widest opacity-80">{label}</p>
      <p className="text-5xl font-extrabold leading-tight tabular-nums flex items-center gap-3">
        {value ?? "—"}
        {pulse && value > 0 && (
          <span className="relative flex h-3.5 w-3.5">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-60" />
            <span className="relative inline-flex rounded-full h-3.5 w-3.5 bg-white" />
          </span>
        )}
      </p>
      {sub && <p className="text-xs opacity-80 mt-1">{sub}</p>}
      <div className="absolute -right-6 -bottom-8 opacity-15">
        <Users size={110} />
      </div>
    </div>
  );
}

/** Pure-SVG hourly IN/OUT bars — no chart lib needed. Windows to the active
 *  part of the day so the bars are visible without horizontal scrolling. */
function HourlyFlow({ hourly }) {
  const all = Array.from({ length: 24 }, (_, h) => {
    const row = (hourly ?? []).find((r) => Number(r.h) === h);
    return { h, ins: Number(row?.ins ?? 0), outs: Number(row?.outs ?? 0) };
  });
  const active = all.filter((r) => r.ins || r.outs).map((r) => r.h);
  const from = Math.max(0, Math.min(active.length ? Math.min(...active) - 1 : 6, 6));
  const to = Math.min(23, Math.max(active.length ? Math.max(...active) + 1 : 21, 21));
  const hours = all.slice(from, to + 1);
  const max = Math.max(1, ...hours.map((r) => Math.max(r.ins, r.outs)));
  const W = hours.length * 26, H = 120, mid = H / 2;
  return (
    <div className="overflow-x-auto">
      <svg width={W} height={H + 22} className="block">
        <line x1="0" y1={mid} x2={W} y2={mid} stroke="#e5e7eb" strokeWidth="1" />
        {hours.map(({ h, ins, outs }, i) => (
          <g key={h} transform={`translate(${i * 26}, 0)`}>
            {ins > 0 && (
              <rect x="5" y={mid - (ins / max) * (mid - 6)} width="7" rx="2"
                height={(ins / max) * (mid - 6)} fill="#0d9488">
                <title>{`${h}:00 — ${ins} IN`}</title>
              </rect>
            )}
            {outs > 0 && (
              <rect x="14" y={mid} width="7" rx="2"
                height={(outs / max) * (mid - 6)} fill="#3b82f6">
                <title>{`${h}:00 — ${outs} OUT`}</title>
              </rect>
            )}
            {h % 3 === 0 && (
              <text x="13" y={H + 16} textAnchor="middle" fontSize="9" fill="#9ca3af">{h}</text>
            )}
          </g>
        ))}
      </svg>
      <div className="flex gap-4 text-[11px] text-gray-500 mt-1">
        <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-teal-600 inline-block" /> IN (up)</span>
        <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-blue-500 inline-block" /> OUT (down)</span>
      </div>
    </div>
  );
}

/**
 * Live Board — the whole site at a glance: who is inside, at which gate or
 * department, today's flow, and the freshest events. Auto-refreshes.
 */
export default function LiveBoard() {
  const navigate = useNavigate();
  const { user } = useAuth();

  const { data, dataUpdatedAt } = useQuery({
    queryKey: ["live-board"],
    queryFn: () => api.get("/attendance/live-board").then((r) => r.data),
    refetchInterval: REFRESH_MS,
    refetchIntervalInBackground: false,
  });

  const occupancy = data?.expected > 0
    ? Math.min(100, Math.round((data.inside_total / data.expected) * 100))
    : null;

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <Activity className="text-brand-600" size={24} /> Live Board
            <span className="relative flex h-2.5 w-2.5 ml-1">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500" />
            </span>
          </h1>
          <p className="text-sm text-gray-500 mt-1">
            {data?.gate_scope
              ? <>Your gate: <b>{data.gate_scope}</b> — live view, updates every {REFRESH_MS / 1000}s</>
              : <>Who is where, right now — updates every {REFRESH_MS / 1000}s</>}
          </p>
        </div>
        <span className="text-xs text-gray-400 flex items-center gap-1">
          <Clock size={12} /> updated {dataUpdatedAt ? format(new Date(dataUpdatedAt), "hh:mm:ss a") : "…"}
        </span>
      </div>

      {/* Hero stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <HeroTile label="Inside now" value={data?.inside_total} pulse
          tone="bg-gradient-to-br from-teal-600 to-emerald-700"
          sub={occupancy != null ? `${occupancy}% of ${data.expected} expected today` : "workers currently on site"} />
        <HeroTile label="Expected today" value={data?.expected}
          tone="bg-gradient-to-br from-slate-600 to-slate-800"
          sub="approved deployments covering today" />
        <HeroTile label="Check-ins today" value={data?.in_today}
          tone="bg-gradient-to-br from-brand-600 to-indigo-700"
          sub="IN events since midnight" />
        <HeroTile label="Check-outs today" value={data?.out_today}
          tone="bg-gradient-to-br from-blue-500 to-blue-700"
          sub="OUT events since midnight" />
      </div>

      {/* Occupancy bar */}
      {occupancy != null && (
        <div className="card py-3">
          <div className="flex justify-between text-xs text-gray-500 mb-1.5">
            <span>Site occupancy</span>
            <span><b className="text-gray-800">{data.inside_total}</b> inside / {data.expected} expected</span>
          </div>
          <div className="h-3 rounded-full bg-gray-100 overflow-hidden">
            <div
              className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all duration-700"
              style={{ width: `${occupancy}%` }}
            />
          </div>
        </div>
      )}

      {/* Gates / departments */}
      <div>
        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3 flex items-center gap-2">
          <DoorOpen size={15} /> Gates &amp; departments
        </h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          {(data?.gates ?? []).filter((g) => g.count > 0).map((g) => (
            <div key={g.name}
              className="card relative overflow-hidden transition-shadow hover:shadow-md border-teal-200">
              <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-teal-500 to-emerald-400" />
              <div className="flex items-start justify-between">
                <div className="min-w-0">
                  <p className="font-semibold text-gray-900 truncate flex items-center gap-1.5">
                    <MapPin size={13} className="text-teal-600" />
                    {g.name}
                  </p>
                  <p className="text-[11px] text-gray-400 mt-0.5">
                    last activity {format(new Date(g.last_at), "hh:mm a")}
                  </p>
                </div>
                <span className="text-3xl font-extrabold tabular-nums text-teal-700">
                  {g.count}
                </span>
              </div>
              <div className="flex items-center mt-3 -space-x-2">
                {g.workers.slice(0, 10).map((w) => (
                  <button key={w.worker_id} onClick={() => navigate(`/workers/${w.worker_id}`)}
                    className="hover:z-10 hover:scale-110 transition-transform">
                    <Avatar w={w} />
                  </button>
                ))}
                {g.count > 10 && (
                  <span className="w-9 h-9 rounded-full ring-2 ring-white bg-gray-100 text-[11px] font-bold text-gray-600 flex items-center justify-center">
                    +{g.count - 10}
                  </span>
                )}
              </div>
            </div>
          ))}
          {data && !(data.gates ?? []).some((g) => g.count > 0) && (
            <div className="card col-span-full text-center py-10 text-gray-400">
              Nobody inside right now — the board lights up with the first check-in.
            </div>
          )}
        </div>
        {/* Empty gates collapse into one quiet strip — the board stays short */}
        {(data?.gates ?? []).some((g) => g.count === 0) && (
          <div className="flex flex-wrap gap-2 mt-3">
            {(data?.gates ?? []).filter((g) => g.count === 0).map((g) => (
              <span key={g.name}
                className="inline-flex items-center gap-1 text-xs text-gray-400 bg-gray-50 border border-gray-100 rounded-full px-3 py-1">
                <MapPin size={10} /> {g.name} · 0
              </span>
            ))}
          </div>
        )}
      </div>

      {/* Flow + live feed */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div className="card">
          <h2 className="font-semibold text-gray-900 mb-3">Today's flow, hour by hour</h2>
          <HourlyFlow hourly={data?.hourly} />
        </div>

        <div className="card p-0 overflow-hidden">
          <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">Live activity</h2>
            <span className="text-[11px] text-gray-400">latest 20 today</span>
          </div>
          <div className="divide-y divide-gray-50 max-h-[360px] overflow-y-auto">
            {(data?.recent ?? []).map((e) => (
              <button key={e.id} onClick={() => navigate(`/workers/${e.worker_id}`)}
                className="w-full flex items-center gap-3 px-5 py-2.5 hover:bg-gray-50/70 text-left">
                <Avatar w={e} size="w-8 h-8" ring="ring-1 ring-gray-100" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-gray-900 truncate">{e.name}</p>
                  <p className="text-[11px] text-gray-400 truncate">
                    {e.gate} · <MethodIcon method={e.method} /> {e.method}
                  </p>
                </div>
                <span className={`badge text-xs shrink-0 ${e.type === "IN" ? "badge-green" : "badge-blue"}`}>
                  {e.type === "IN" ? <LogIn size={9} className="mr-0.5 inline" /> : <LogOut size={9} className="mr-0.5 inline" />}
                  {e.type}
                </span>
                <span className="text-xs text-gray-400 tabular-nums shrink-0">
                  {format(new Date(e.at), "hh:mm a")}
                </span>
              </button>
            ))}
            {data && !(data.recent ?? []).length && (
              <p className="text-center text-gray-400 py-10 text-sm">No activity yet today.</p>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
