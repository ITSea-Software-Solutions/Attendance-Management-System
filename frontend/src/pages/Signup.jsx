import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import toast from "react-hot-toast";
import { Building2, HardHat, Check, Fingerprint, ChevronLeft } from "lucide-react";
import api from "@/lib/axios";
import PasswordInput from "@/components/PasswordInput";
import { useAuth } from "@/contexts/AuthContext";

/**
 * Public SaaS signup — register as a Company (deploys workers at sites) or a
 * Vendor (supplies workers). Minimal mandatory fields; GST/PAN optional.
 * Step 1: account details → Step 2: pick a plan → account starts on Trial
 * (paid plans file an offline-payment upgrade request) → auto-login.
 */

const PLAN_ORDER = ["trial", "professional", "enterprise"];

export default function Signup() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [step, setStep] = useState(0);
  const [busy, setBusy] = useState(false);
  const [plans, setPlans] = useState(null);
  const [form, setForm] = useState({
    org_type: "company",
    org_name: "", name: "", email: "", password: "", phone: "",
    address: "", city: "", state: "", pin: "",
    gst_number: "", pan_number: "",
    plan: "trial",
  });
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const toPlans = async () => {
    if (form.org_name.trim().length < 3) return toast.error("Organisation name is required (min 3 characters).");
    if (!form.name.trim()) return toast.error("Your name is required.");
    if (!/^\S+@\S+\.\S+$/.test(form.email)) return toast.error("Enter a valid email.");
    if (form.password.length < 8) return toast.error("Password must be at least 8 characters (letters + numbers).");
    // NOTE: do NOT fetch /plan here — it requires auth, and a 401 triggers the
    // axios interceptor's hard redirect to /login, wiping the signup form.
    // The FALLBACK catalogue below mirrors config/plans.php.
    setStep(1);
  };

  const submit = async () => {
    setBusy(true);
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ""));
      const r = await api.post("/signup", payload);
      toast.success(r.data.message, { duration: 6000 });
      await login(form.email, form.password);
      navigate("/dashboard");
    } catch (err) {
      const errs = err.response?.data?.errors;
      toast.error(errs ? Object.values(errs).flat()[0] : (err.response?.data?.message ?? "Signup failed — try again."));
      if (errs && (errs.org_name || errs.email || errs.password)) setStep(0);
    } finally {
      setBusy(false);
    }
  };

  const FALLBACK = {
    trial:        { label: "Trial",        price: "Free",                     users: 3,  workers: 10,  links: 3,  support: "Community" },
    professional: { label: "Professional", price: "₹4,999/mo (billed offline)", users: 25, workers: 500, links: 25, support: "Email support" },
    enterprise:   { label: "Enterprise",   price: "Custom (contact us)",       users: 100, workers: 5000, links: 100, support: "Priority + onboarding" },
  };
  const catalogue = plans ?? FALLBACK;
  const fmt = (v) => (v === null || v === undefined ? "Unlimited" : v);

  return (
    <div className="min-h-screen bg-gray-50 flex items-start justify-center py-10 px-4">
      <div className="w-full max-w-3xl">
        <div className="text-center mb-8">
          <Fingerprint size={44} className="mx-auto text-brand-600" />
          <h1 className="text-2xl font-bold text-gray-900 mt-2">Create your TrueCrew account</h1>
          <p className="text-sm text-gray-500 mt-1">
            Every worker verified — Aadhaar identity, biometric attendance, offline-first. Start free.
          </p>
        </div>

        {step === 0 && (
          <div className="card max-w-xl mx-auto space-y-4">
            {/* org type */}
            <div>
              <label className="label">I am registering a…</label>
              <div className="grid grid-cols-2 gap-3">
                {[
                  { v: "company", icon: Building2, t: "Company", d: "We host workers at our sites and track their attendance" },
                  { v: "vendor",  icon: HardHat,   t: "Vendor / Contractor", d: "We supply workers and deploy them to companies" },
                ].map(({ v, icon: Icon, t, d }) => (
                  <button key={v} type="button" onClick={() => setForm((f) => ({ ...f, org_type: v }))}
                    className={`p-4 rounded-lg border text-left transition-colors ${form.org_type === v ? "border-brand-500 bg-brand-50" : "border-gray-200 hover:border-gray-300"}`}>
                    <Icon size={20} className={form.org_type === v ? "text-brand-600" : "text-gray-400"} />
                    <div className="font-semibold text-sm mt-1.5">{t}</div>
                    <div className="text-xs text-gray-500 mt-0.5">{d}</div>
                  </button>
                ))}
              </div>
            </div>

            <div><label className="label">Organisation name *</label>
              <input className="input" value={form.org_name} onChange={set("org_name")} placeholder={form.org_type === "company" ? "Acme Manufacturing Ltd" : "Apex Labor Solutions"} /></div>
            <div className="grid sm:grid-cols-2 gap-3">
              <div><label className="label">Your name *</label><input className="input" value={form.name} onChange={set("name")} /></div>
              <div><label className="label">Phone</label><input className="input" value={form.phone} onChange={set("phone")} /></div>
            </div>
            <div className="grid sm:grid-cols-2 gap-3">
              <div><label className="label">Email * <span className="text-gray-400 font-normal">(your login)</span></label>
                <input className="input" type="email" value={form.email} onChange={set("email")} /></div>
              <div><label className="label">Password * <span className="text-gray-400 font-normal">(8+, letters & numbers)</span></label>
                <PasswordInput value={form.password} onChange={set("password")} /></div>
            </div>
            <div><label className="label">Address</label><input className="input" value={form.address} onChange={set("address")} /></div>
            <div className="grid grid-cols-3 gap-3">
              <div><label className="label">City</label><input className="input" value={form.city} onChange={set("city")} /></div>
              <div><label className="label">State</label><input className="input" value={form.state} onChange={set("state")} /></div>
              <div><label className="label">PIN</label><input className="input" value={form.pin} onChange={set("pin")} maxLength={6} /></div>
            </div>

            <details className="rounded-lg border border-gray-200 p-3">
              <summary className="text-sm font-medium text-gray-600 cursor-pointer">Legal details (optional — add later anytime)</summary>
              <div className="grid sm:grid-cols-2 gap-3 mt-3">
                <div><label className="label">GSTIN</label><input className="input" value={form.gst_number} onChange={set("gst_number")} maxLength={15} /></div>
                {form.org_type === "vendor" && (
                  <div><label className="label">PAN</label><input className="input" value={form.pan_number} onChange={set("pan_number")} maxLength={10} /></div>
                )}
              </div>
            </details>

            <button className="btn-primary w-full justify-center" onClick={toPlans}>Continue — choose your plan</button>
            <p className="text-center text-sm text-gray-500">
              Already have an account? <Link to="/login" className="text-brand-600 font-medium">Sign in</Link>
            </p>
          </div>
        )}

        {step === 1 && (
          <div>
            <div className="grid md:grid-cols-3 gap-4">
              {PLAN_ORDER.map((key) => {
                const p = catalogue[key];
                const selected = form.plan === key;
                return (
                  <button key={key} type="button" onClick={() => setForm((f) => ({ ...f, plan: key }))}
                    className={`card text-left relative transition-shadow ${selected ? "ring-2 ring-brand-500 shadow-md" : "hover:shadow-md"}`}>
                    {key === "professional" && (
                      <span className="absolute -top-2.5 left-1/2 -translate-x-1/2 badge badge-green text-[11px]">Most popular</span>
                    )}
                    <div className="font-bold text-gray-900">{p.label}</div>
                    <div className="text-sm text-brand-700 font-semibold mt-0.5">{p.price}</div>
                    <ul className="mt-3 space-y-1.5 text-sm text-gray-600">
                      <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {fmt(p.users)} user logins</li>
                      <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {fmt(p.workers)} workers</li>
                      <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {fmt(p.links)} company–vendor links</li>
                      <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> Aadhaar + biometric attendance</li>
                      <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> Android & Windows apps</li>
                      <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {p.support}</li>
                    </ul>
                  </button>
                );
              })}
            </div>
            {form.plan !== "trial" && (
              <div className="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
                Payment is settled offline for now: you'll start on <b>Trial</b> immediately and our team will
                contact you to activate <b>{catalogue[form.plan].label}</b>.
              </div>
            )}
            <div className="flex items-center justify-between mt-5">
              <button className="btn-secondary" onClick={() => setStep(0)}><ChevronLeft size={15} /> Back</button>
              <button className="btn-primary" disabled={busy} onClick={submit}>
                {busy ? "Creating account…" : form.plan === "trial" ? "Start free on Trial" : `Sign up & request ${catalogue[form.plan].label}`}
              </button>
            </div>
            <p className="text-xs text-gray-400 text-center mt-4">
              By creating an account you agree to the{" "}
              <a href="/terms.html" target="_blank" rel="noreferrer" className="underline">Terms of Service</a> and{" "}
              <a href="/privacy.html" target="_blank" rel="noreferrer" className="underline">Privacy Policy</a>{" "}
              (incl. Aadhaar & biometric data handling).
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
