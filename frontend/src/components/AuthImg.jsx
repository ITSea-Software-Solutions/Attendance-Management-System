import { useState, useEffect } from "react";
import api from "@/lib/axios";

/**
 * Private-disk images (worker photos, Aadhaar photos, gate proof captures)
 * need the Bearer token, so a plain <img src> can't load them — fetch as an
 * authenticated blob and render an object URL instead.
 */
export default function AuthImg({ url, alt, className, fallback = null }) {
  const [src, setSrc] = useState(null);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    let obj; let alive = true;
    setSrc(null); setFailed(false);
    if (!url) { setFailed(true); return undefined; }
    api.get(url, { responseType: "blob" })
      .then((r) => { if (alive) { obj = URL.createObjectURL(r.data); setSrc(obj); } })
      .catch(() => { if (alive) setFailed(true); });
    return () => { alive = false; if (obj) URL.revokeObjectURL(obj); };
  }, [url]);
  if (failed || !url) return fallback;
  if (!src) return <div className={`${className} bg-gray-100 animate-pulse`} />;
  return <img src={src} alt={alt} className={className} />;
}
