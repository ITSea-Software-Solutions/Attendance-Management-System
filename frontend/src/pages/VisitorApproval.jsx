import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import axios from "axios";
import { Check, X, Car, Clock, ShieldCheck, AlertCircle, Loader2 } from "lucide-react";

/**
 * PUBLIC page — the host taps this from a WhatsApp or SMS link.
 *
 * Hosts are plant staff, not system users, so there is no login here: the
 * token in the URL is the credential. Deliberately plain and phone-shaped —
 * a guard is waiting at the gate while this is being read.
 */
export default function VisitorApproval() {
  const { token } = useParams();
  const api = axios.create({ baseURL: "/api", headers: { Accept: "application/json" } });

  const [pass, setPass] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(null);

  useEffect(() => {
    api.get(`/visitor-pass/${token}`)
      .then((r) => setPass(r.data))
      .catch((e) => setError(e.response?.data?.message ?? "This link is not valid."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const decide = async (decision) => {
    setBusy(true);
    try {
      const r = await api.post(`/visitor-pass/${token}/decide`, { decision });
      setDone({ decision, message: r.data?.message });
    } catch (e) {
      setError(e.response?.data?.message ?? "Could not record your answer.");
    } finally {
      setBusy(false);
    }
  };

  const Shell = ({ children }) => (
    <div className="min-h-screen bg-gray-50 flex items-start justify-center p-4">
      <div className="w-full max-w-md mt-6 space-y-4">
        <div className="text-center">
          <p className="text-xl font-extrabold text-brand-700 tracking-tight">TRUECREW</p>
          <p className="text-[11px] tracking-[2px] text-gray-400 font-semibold">VISITOR APPROVAL</p>
        </div>
        {children}
      </div>
    </div>
  );

  if (error) {
    return (
      <Shell>
        <div className="card text-center space-y-2">
          <AlertCircle className="mx-auto text-amber-500" size={28} />
          <p className="font-semibold text-gray-900">{error}</p>
          <p className="text-sm text-gray-500">
            If the visitor is still waiting, please call the gate directly.
          </p>
        </div>
      </Shell>
    );
  }

  if (!pass) {
    return (
      <Shell>
        <div className="card flex items-center justify-center py-10">
          <Loader2 className="animate-spin text-gray-400" size={22} />
        </div>
      </Shell>
    );
  }

  const settled = done || pass.status !== "pending";
  const finalDecision = done?.decision ?? pass.status;

  return (
    <Shell>
      <div className="card space-y-4">
        <div className="text-center">
          <p className="text-sm text-gray-500">
            Someone is at the gate to see <b className="text-gray-800">{pass.host_name}</b>
          </p>
          <h1 className="text-2xl font-bold text-gray-900 mt-1">{pass.guest_name}</h1>
          {pass.purpose && <p className="text-sm text-gray-600 mt-0.5">{pass.purpose}</p>}
        </div>

        <div className="flex gap-2">
          {pass.has_photo && (
            <img src={`/api/visitor-pass/${token}/photo`} alt={pass.guest_name}
              className="flex-1 h-40 object-cover rounded-xl border border-gray-200" />
          )}
          {pass.has_vehicle_photo && (
            <img src={`/api/visitor-pass/${token}/photo?type=vehicle`} alt="vehicle"
              className="flex-1 h-40 object-cover rounded-xl border border-gray-200" />
          )}
        </div>

        <div className="text-sm text-gray-600 space-y-1">
          {pass.guest_phone && <p>📞 {pass.guest_phone}</p>}
          {pass.vehicle_number && (
            <p className="flex items-center gap-1.5">
              <Car size={14} className="text-gray-400" />
              <span className="font-mono">{pass.vehicle_number}</span>
            </p>
          )}
          <p className="flex items-center gap-1.5">
            <Clock size={14} className="text-gray-400" />
            {pass.gate} · {pass.company_name}
          </p>
          <p className="text-xs text-gray-400">Pass {pass.code}</p>
        </div>

        {settled ? (
          <div className={`rounded-xl px-4 py-4 text-center ${
            finalDecision === "approved"
              ? "bg-emerald-50 border border-emerald-200"
              : "bg-red-50 border border-red-200"}`}>
            {finalDecision === "approved"
              ? <ShieldCheck className="mx-auto text-emerald-600" size={26} />
              : <X className="mx-auto text-red-500" size={26} />}
            <p className={`font-semibold mt-1 ${
              finalDecision === "approved" ? "text-emerald-800" : "text-red-700"}`}>
              {done?.message ?? (finalDecision === "approved"
                ? "Approved — the gate can let them in."
                : "Denied — the gate has been told.")}
            </p>
            <p className="text-xs text-gray-500 mt-1">You can close this page.</p>
          </div>
        ) : !pass.actionable ? (
          <div className="rounded-xl bg-gray-50 border border-gray-200 px-4 py-4 text-center">
            <AlertCircle className="mx-auto text-gray-400" size={24} />
            <p className="text-sm text-gray-600 mt-1">
              This request has expired. Please call the gate directly.
            </p>
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-3 pt-1">
            <button disabled={busy} onClick={() => decide("approved")}
              className="rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold py-4 flex items-center justify-center gap-2">
              <Check size={18} /> Allow
            </button>
            <button disabled={busy} onClick={() => decide("denied")}
              className="rounded-xl bg-white border-2 border-red-200 hover:bg-red-50 disabled:opacity-60 text-red-700 font-semibold py-4 flex items-center justify-center gap-2">
              <X size={18} /> Deny
            </button>
          </div>
        )}
      </div>

      <p className="text-center text-[11px] text-gray-400">
        You are seeing this because you were named as the host. No login needed.
      </p>
    </Shell>
  );
}
