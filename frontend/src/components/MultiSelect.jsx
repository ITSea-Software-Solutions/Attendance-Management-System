import { useEffect, useMemo, useRef, useState } from "react";
import { Check, ChevronDown, Search, X } from "lucide-react";

/**
 * Checkbox dropdown for picking one, many, or all of something.
 *
 * `value` is an array of ids; an EMPTY array means "All" (no filter), which
 * keeps the caller's query string clean — send the param only when non-empty.
 */
export default function MultiSelect({
  options = [],            // [{ id, name, sub? }]
  value = [],
  onChange,
  label = "Items",
  allLabel = "All",
  width = "w-56",
  disabled = false,
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const boxRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => { if (!boxRef.current?.contains(e.target)) setOpen(false); };
    const onEsc = (e) => { if (e.key === "Escape") setOpen(false); };
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onEsc);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      document.removeEventListener("keydown", onEsc);
    };
  }, [open]);

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return options;
    return options.filter((o) =>
      `${o.name} ${o.sub ?? ""}`.toLowerCase().includes(s));
  }, [options, q]);

  const selectedNames = options.filter((o) => value.includes(o.id)).map((o) => o.name);
  const summary = value.length === 0
    ? `${label}: ${allLabel}`
    : value.length === 1
      ? selectedNames[0] ?? `1 ${label.toLowerCase()}`
      : `${value.length} ${label.toLowerCase()} selected`;

  const toggle = (id) =>
    onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id]);

  return (
    <div className={`relative ${width}`} ref={boxRef}>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        className={`input flex items-center justify-between gap-2 w-full text-left ${
          value.length ? "border-brand-400 text-gray-900" : "text-gray-600"} ${
          disabled ? "opacity-50 cursor-not-allowed" : ""}`}
      >
        <span className="truncate text-sm">{summary}</span>
        <span className="flex items-center gap-1 shrink-0">
          {value.length > 0 && (
            <X size={14} className="text-gray-400 hover:text-gray-700"
              onClick={(e) => { e.stopPropagation(); onChange([]); }} />
          )}
          <ChevronDown size={15} className="text-gray-400" />
        </span>
      </button>

      {open && (
        <div className="absolute z-30 mt-1 w-full min-w-[240px] rounded-xl border border-gray-200 bg-white shadow-lg">
          <div className="p-2 border-b border-gray-100">
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" size={14} />
              <input
                autoFocus
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder={`Search ${label.toLowerCase()}...`}
                className="input pl-8 py-1.5 text-sm w-full"
              />
            </div>
          </div>

          <div className="flex items-center justify-between px-3 py-1.5 border-b border-gray-100 text-xs">
            <button type="button" className="font-medium text-brand-700 hover:underline"
              onClick={() => onChange(filtered.map((o) => o.id))}>
              Select {q ? "matching" : "all"}
            </button>
            <button type="button" className="text-gray-500 hover:underline"
              onClick={() => onChange([])}>
              Clear ({allLabel})
            </button>
          </div>

          <div className="max-h-60 overflow-y-auto py-1">
            {filtered.length === 0 && (
              <p className="text-sm text-gray-400 text-center py-4">Nothing matches</p>
            )}
            {filtered.map((o) => {
              const on = value.includes(o.id);
              return (
                <button
                  type="button"
                  key={o.id}
                  onClick={() => toggle(o.id)}
                  className="w-full flex items-center gap-2.5 px-3 py-1.5 text-left hover:bg-gray-50"
                >
                  <span className={`w-4 h-4 rounded border flex items-center justify-center shrink-0 ${
                    on ? "bg-brand-600 border-brand-600 text-white" : "border-gray-300"}`}>
                    {on && <Check size={11} strokeWidth={3} />}
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm text-gray-800 truncate">{o.name}</span>
                    {o.sub && <span className="block text-[11px] text-gray-400 truncate">{o.sub}</span>}
                  </span>
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
