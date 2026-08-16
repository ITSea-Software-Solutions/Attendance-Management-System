import { useEffect, useRef, useState } from "react";
import { Bell, CheckCheck } from "lucide-react";
import api from "@/lib/axios";

/**
 * Notification center: badge with unread count, dropdown with the latest
 * rows. Polls lightly (60s) — enough for gate/admin workflows without
 * websocket infra.
 */
export default function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [rows, setRows] = useState([]);
  const [unread, setUnread] = useState(0);
  const boxRef = useRef(null);

  const load = async () => {
    try {
      const r = await api.get("/notifications");
      setRows(r.data.notifications ?? []);
      setUnread(r.data.unread ?? 0);
    } catch { /* silent — bell is never worth an error toast */ }
  };

  useEffect(() => {
    load();
    const t = setInterval(load, 60000);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    const onClick = (e) => {
      if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  const markAll = async () => {
    try {
      await api.post("/notifications/read", {});
      setUnread(0);
      setRows((r) => r.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
    } catch { /* ignore */ }
  };

  const fmt = (iso) => {
    const d = new Date(iso);
    return `${d.getDate()}/${d.getMonth() + 1} ${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
  };

  return (
    <div className="relative" ref={boxRef}>
      <button
        onClick={() => { setOpen(!open); if (!open) load(); }}
        className="p-2 rounded-lg hover:bg-gray-100 text-gray-500 relative"
        title="Notifications"
      >
        <Bell size={18} />
        {unread > 0 && (
          <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center animate-pulse">
            {unread > 99 ? "99+" : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-96 max-w-[90vw] bg-white rounded-xl shadow-2xl border border-gray-200 z-50 overflow-hidden">
          <div className="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
            <span className="text-sm font-semibold text-gray-800">Notifications</span>
            {unread > 0 && (
              <button onClick={markAll} className="text-xs text-brand-600 font-medium inline-flex items-center gap-1">
                <CheckCheck size={13} /> Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto">
            {rows.length === 0 && (
              <p className="text-sm text-gray-400 text-center py-8">Nothing yet — approvals, registrations and alerts appear here.</p>
            )}
            {rows.map((n) => (
              <div key={n.id} className={`px-4 py-3 border-b border-gray-50 ${n.read_at ? "" : "bg-brand-50/50"}`}>
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm font-medium text-gray-800">{n.title}</p>
                  {!n.read_at && <span className="mt-1 w-2 h-2 rounded-full bg-brand-600 shrink-0" />}
                </div>
                {n.body && <p className="text-xs text-gray-500 mt-0.5 whitespace-pre-line">{n.body}</p>}
                <p className="text-[11px] text-gray-400 mt-1">{fmt(n.created_at)}</p>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
