/**
 * Tiny dependency-free SVG charts for the dashboard.
 * All charts are responsive (viewBox + w-full) and use the brand palette.
 */

const BRAND = "#2563eb"; // blue-600
const PALETTE = ["#2563eb", "#10b981", "#f59e0b", "#8b5cf6", "#ef4444", "#06b6d4", "#f97316", "#64748b"];

/** Smooth area/line chart for a daily series [{d, value}]. */
export function AreaChart({ data, height = 170, color = BRAND, label = "" }) {
  const W = 600, H = height, padX = 6, padTop = 14, padBot = 22;
  if (!data?.length) return null;
  const max = Math.max(1, ...data.map((p) => p.value));
  const iw = W - padX * 2, ih = H - padTop - padBot;
  const x = (i) => padX + (data.length === 1 ? iw / 2 : (i / (data.length - 1)) * iw);
  const y = (v) => padTop + ih - (v / max) * ih;
  const pts = data.map((p, i) => [x(i), y(p.value)]);
  const line = pts.map(([px, py], i) => `${i === 0 ? "M" : "L"}${px.toFixed(1)},${py.toFixed(1)}`).join(" ");
  const area = `${line} L${pts[pts.length - 1][0].toFixed(1)},${padTop + ih} L${pts[0][0].toFixed(1)},${padTop + ih} Z`;
  const last = pts[pts.length - 1];
  const gid = `ag-${label.replace(/\W/g, "")}-${color.slice(1)}`;
  const fmt = (d) => new Date(d + "T00:00:00").toLocaleDateString("en-IN", { day: "numeric", month: "short" });
  // x labels: first, middle, last
  const ticks = [0, Math.floor((data.length - 1) / 2), data.length - 1];
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={label}>
      <defs>
        <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.28" />
          <stop offset="100%" stopColor={color} stopOpacity="0.02" />
        </linearGradient>
      </defs>
      {[0.25, 0.5, 0.75].map((f) => (
        <line key={f} x1={padX} x2={W - padX} y1={padTop + ih * f} y2={padTop + ih * f}
          stroke="#e5e7eb" strokeDasharray="3 4" strokeWidth="1" />
      ))}
      <path d={area} fill={`url(#${gid})`} />
      <path d={line} fill="none" stroke={color} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
      {data.map((p, i) => (
        <circle key={p.d} cx={pts[i][0]} cy={pts[i][1]} r="7" fill="transparent">
          <title>{`${fmt(p.d)} — ${p.value}`}</title>
        </circle>
      ))}
      <circle cx={last[0]} cy={last[1]} r="4" fill={color} stroke="#fff" strokeWidth="2" />
      <text x={Math.min(last[0], W - padX - 4)} y={Math.max(11, last[1] - 9)} textAnchor="end"
        fontSize="11" fontWeight="700" fill={color}>{data[data.length - 1].value}</text>
      {ticks.map((i) => (
        <text key={i} x={x(i)} y={H - 6} fontSize="10" fill="#9ca3af"
          textAnchor={i === 0 ? "start" : i === data.length - 1 ? "end" : "middle"}>
          {fmt(data[i].d)}
        </text>
      ))}
    </svg>
  );
}

/** Vertical bars for a short series [{label, value, hot?}] (e.g. this week). */
export function BarChart({ data, height = 170, color = BRAND }) {
  const W = 600, H = height, padTop = 16, padBot = 22;
  if (!data?.length) return null;
  const max = Math.max(1, ...data.map((p) => p.value));
  const ih = H - padTop - padBot;
  const slot = W / data.length, bw = Math.min(46, slot * 0.55);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label="bar chart">
      {data.map((p, i) => {
        const bh = Math.max(p.value > 0 ? 3 : 0, (p.value / max) * ih);
        const bx = slot * i + (slot - bw) / 2;
        const by = padTop + ih - bh;
        return (
          <g key={i}>
            <rect x={bx} y={padTop} width={bw} height={ih} rx="7" fill="#f3f4f6" />
            <rect x={bx} y={by} width={bw} height={bh} rx="7"
              fill={p.hot ? color : `${color}99`}>
              <title>{`${p.label} — ${p.value}`}</title>
            </rect>
            {p.value > 0 && (
              <text x={bx + bw / 2} y={Math.max(11, by - 5)} textAnchor="middle" fontSize="10.5"
                fontWeight="700" fill={p.hot ? color : "#6b7280"}>{p.value}</text>
            )}
            <text x={bx + bw / 2} y={H - 6} textAnchor="middle" fontSize="10"
              fontWeight={p.hot ? "700" : "400"} fill={p.hot ? "#111827" : "#9ca3af"}>{p.label}</text>
          </g>
        );
      })}
    </svg>
  );
}

/** Donut with legend: segments [{label, value}]. */
export function Donut({ segments, size = 132, centerLabel = "" }) {
  const total = (segments || []).reduce((s, x) => s + x.value, 0);
  if (!total) return <p className="text-sm text-gray-400 py-6 text-center">No data yet today</p>;
  const R = 44, C = 2 * Math.PI * R;
  let acc = 0;
  return (
    <div className="flex items-center gap-5">
      <svg viewBox="0 0 110 110" style={{ width: size, height: size }} className="shrink-0 -rotate-90">
        <circle cx="55" cy="55" r={R} fill="none" stroke="#f3f4f6" strokeWidth="14" />
        {segments.map((s, i) => {
          const frac = s.value / total;
          const dash = `${(frac * C).toFixed(2)} ${(C - frac * C).toFixed(2)}`;
          const off = (-acc * C).toFixed(2);
          acc += frac;
          return (
            <circle key={s.label} cx="55" cy="55" r={R} fill="none"
              stroke={PALETTE[i % PALETTE.length]} strokeWidth="14"
              strokeDasharray={dash} strokeDashoffset={off} strokeLinecap="butt">
              <title>{`${s.label}: ${s.value}`}</title>
            </circle>
          );
        })}
        <g className="rotate-90" style={{ transformOrigin: "55px 55px" }}>
          <text x="55" y="52" textAnchor="middle" fontSize="20" fontWeight="800" fill="#111827">{total}</text>
          <text x="55" y="67" textAnchor="middle" fontSize="8.5" fill="#6b7280">{centerLabel}</text>
        </g>
      </svg>
      <div className="space-y-1.5 min-w-0">
        {segments.map((s, i) => (
          <div key={s.label} className="flex items-center gap-2 text-sm">
            <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: PALETTE[i % PALETTE.length] }} />
            <span className="text-gray-600 truncate">{s.label}</span>
            <span className="font-semibold text-gray-900 ml-auto pl-3">{s.value}</span>
            <span className="text-xs text-gray-400 w-9 text-right">{Math.round((s.value / total) * 100)}%</span>
          </div>
        ))}
      </div>
    </div>
  );
}

/** Compact hourly-flow bars (24 values), windowed to the active part of the day. */
export function HourlyFlow({ values, color = "#10b981" }) {
  if (!values?.length) return null;
  const active = values.map((v, h) => ({ h, v })).filter((x) => x.v > 0);
  const from = active.length ? Math.max(0, Math.min(6, active[0].h - 1)) : 6;
  const to = active.length ? Math.min(23, Math.max(20, active[active.length - 1].h + 1)) : 20;
  const win = [];
  for (let h = from; h <= to; h++) win.push({ h, v: values[h] });
  const max = Math.max(1, ...win.map((x) => x.v));
  const W = 600, H = 110, padBot = 18, ih = H - padBot - 8;
  const slot = W / win.length, bw = Math.min(26, slot * 0.6);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label="hourly IN flow">
      {win.map((x, i) => {
        const bh = Math.max(x.v > 0 ? 3 : 1.5, (x.v / max) * ih);
        return (
          <g key={x.h}>
            <rect x={slot * i + (slot - bw) / 2} y={8 + ih - bh} width={bw} height={bh} rx="4"
              fill={x.v > 0 ? color : "#e5e7eb"}>
              <title>{`${String(x.h).padStart(2, "0")}:00 — ${x.v} IN`}</title>
            </rect>
            {(x.h % 2 === 0) && (
              <text x={slot * i + slot / 2} y={H - 4} textAnchor="middle" fontSize="9.5" fill="#9ca3af">
                {String(x.h).padStart(2, "0")}
              </text>
            )}
          </g>
        );
      })}
    </svg>
  );
}

/** Horizontal presence bars: rows [{label, present, deployed}]. */
export function PresenceBars({ rows }) {
  if (!rows?.length) return <p className="text-sm text-gray-400 py-6 text-center">No presence recorded yet today</p>;
  const max = Math.max(1, ...rows.map((r) => Math.max(r.present, r.deployed)));
  return (
    <div className="space-y-3">
      {rows.map((r) => (
        <div key={r.label}>
          <div className="flex items-baseline justify-between text-sm mb-1">
            <span className="font-medium text-gray-700 truncate pr-3">{r.label}</span>
            <span className="text-xs text-gray-500 shrink-0">
              <b className="text-gray-900 text-sm">{r.present}</b>
              {r.deployed > 0 && <> / {r.deployed} deployed</>}
            </span>
          </div>
          <div className="h-2.5 rounded-full bg-gray-100 overflow-hidden relative">
            {r.deployed > 0 && (
              <div className="absolute inset-y-0 left-0 rounded-full bg-blue-100"
                style={{ width: `${(r.deployed / max) * 100}%` }} />
            )}
            <div className="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-blue-600 to-blue-400"
              style={{ width: `${(r.present / max) * 100}%` }} />
          </div>
        </div>
      ))}
    </div>
  );
}

/** Ranked horizontal bars: rows [{label, value, display?}] (top-N lists). */
export function HBarList({ rows, color = "#2563eb", emptyText = "No data" }) {
  if (!rows?.length) return <p className="text-sm text-gray-400 py-6 text-center">{emptyText}</p>;
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <div className="space-y-2.5">
      {rows.map((r) => (
        <div key={r.label}>
          <div className="flex items-baseline justify-between text-[13px] mb-0.5">
            <span className="font-medium text-gray-700 truncate pr-3">{r.label}</span>
            <span className="font-semibold text-gray-900 shrink-0">{r.display ?? r.value}</span>
          </div>
          <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
            <div className="h-full rounded-full" style={{ width: `${(r.value / max) * 100}%`, background: color }} />
          </div>
        </div>
      ))}
    </div>
  );
}
