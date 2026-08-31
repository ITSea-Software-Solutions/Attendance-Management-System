import { useState } from "react";
import { Lightbulb, X } from "lucide-react";

/**
 * One friendly sentence explaining a page, for people coming from Excel and
 * paper registers. Dismissable; the choice is remembered per page.
 */
export default function PageHint({ id, children }) {
  const key = `hint-dismissed-${id}`;
  const [hidden, setHidden] = useState(() => localStorage.getItem(key) === "1");
  if (hidden) return null;
  return (
    <div className="flex items-start gap-2.5 rounded-xl bg-amber-50 border border-amber-200 px-4 py-2.5 text-sm text-amber-900">
      <Lightbulb size={16} className="shrink-0 mt-0.5 text-amber-500" />
      <p className="flex-1">{children}</p>
      <button
        aria-label="Dismiss hint"
        onClick={() => { localStorage.setItem(key, "1"); setHidden(true); }}
        className="text-amber-400 hover:text-amber-600"
      >
        <X size={14} />
      </button>
    </div>
  );
}
