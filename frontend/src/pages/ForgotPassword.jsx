import { useState } from "react";
import { Link } from "react-router-dom";
import toast from "react-hot-toast";
import { Fingerprint, MailCheck } from "lucide-react";
import api from "@/lib/axios";

/** Self-service password reset — step 1: request the link. */
export default function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [devUrl, setDevUrl] = useState(null); // demo mode (no SMTP) only

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await api.post("/auth/forgot-password", { email });
      setSent(true);
      setDevUrl(r.data.dev_reset_url ?? null);
    } catch (err) {
      toast.error(err.response?.status === 429
        ? "Too many attempts — wait a few minutes."
        : "Could not send the reset link — try again.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
      <div className="card w-full max-w-md">
        <div className="text-center mb-4">
          <Fingerprint size={36} className="mx-auto text-brand-600" />
          <h1 className="text-xl font-bold text-gray-900 mt-2">Reset your password</h1>
        </div>
        {sent ? (
          <div className="space-y-3 text-center">
            <MailCheck size={32} className="mx-auto text-brand-600" />
            <p className="text-sm text-gray-600">
              If that email is registered, a reset link is on its way. It's valid for 60 minutes.
            </p>
            {devUrl && (
              <div className="p-3 rounded-lg bg-amber-50 border border-amber-200 text-left">
                <p className="text-xs font-semibold text-amber-800 mb-1">Demo mode (no email provider configured):</p>
                <a href={devUrl} className="text-xs text-brand-700 underline break-all">{devUrl}</a>
              </div>
            )}
            <Link to="/login" className="text-brand-600 text-sm font-medium">Back to sign in</Link>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <div>
              <label className="label">Your login email</label>
              <input className="input" type="email" required value={email}
                     onChange={(e) => setEmail(e.target.value)} placeholder="you@company.com" />
            </div>
            <button className="btn-primary w-full justify-center" disabled={busy}>
              {busy ? "Sending…" : "Send reset link"}
            </button>
            <p className="text-center text-sm text-gray-500">
              Remembered it? <Link to="/login" className="text-brand-600 font-medium">Sign in</Link>
            </p>
          </form>
        )}
      </div>
    </div>
  );
}
