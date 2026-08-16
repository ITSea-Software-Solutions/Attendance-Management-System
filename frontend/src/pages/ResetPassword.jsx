import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import toast from "react-hot-toast";
import { Fingerprint } from "lucide-react";
import api from "@/lib/axios";
import PasswordInput from "@/components/PasswordInput";

/** Self-service password reset — step 2: set the new password (from the emailed link). */
export default function ResetPassword() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    if (password !== confirm) { toast.error("Passwords don't match."); return; }
    setBusy(true);
    try {
      const r = await api.post("/auth/reset-password", {
        token, email, password, password_confirmation: confirm,
      });
      toast.success(r.data.message, { duration: 5000 });
      navigate("/login");
    } catch (err) {
      const errs = err.response?.data?.errors;
      toast.error(errs ? Object.values(errs).flat()[0]
        : (err.response?.data?.message ?? "Reset failed — request a new link."));
    } finally {
      setBusy(false);
    }
  };

  if (!token || !email) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        <div className="card w-full max-w-md text-center space-y-3">
          <p className="text-sm text-gray-600">This reset link is incomplete.</p>
          <Link to="/forgot-password" className="text-brand-600 font-medium text-sm">Request a new link</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
      <div className="card w-full max-w-md">
        <div className="text-center mb-4">
          <Fingerprint size={36} className="mx-auto text-brand-600" />
          <h1 className="text-xl font-bold text-gray-900 mt-2">Choose a new password</h1>
          <p className="text-xs text-gray-400 mt-1">{email}</p>
        </div>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="label">New password (8+, letters & numbers)</label>
            <PasswordInput value={password} onChange={(e) => setPassword(e.target.value)} required />
          </div>
          <div>
            <label className="label">Confirm new password</label>
            <PasswordInput value={confirm} onChange={(e) => setConfirm(e.target.value)} required />
          </div>
          <button className="btn-primary w-full justify-center" disabled={busy}>
            {busy ? "Updating…" : "Update password"}
          </button>
        </form>
      </div>
    </div>
  );
}
