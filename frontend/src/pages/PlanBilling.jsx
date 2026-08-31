import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import toast from "react-hot-toast";
import { Check, ArrowUpCircle, Clock } from "lucide-react";
import api from "@/lib/axios";

/**
 * Plan & Billing — org admins see their current plan, live usage vs limits,
 * and can request an upgrade (offline payment; super admin approves).
 */

const ORDER = ["trial", "professional", "enterprise"];
const fmt = (v) => (v === null || v === undefined ? "Unlimited" : v);

function Meter({ label, used, limit }) {
  if (used === null || used === undefined) return null;
  const unlimited = limit === null || limit === undefined;
  const pct = unlimited ? 0 : Math.min(100, Math.round((used / limit) * 100));
  const full = !unlimited && used >= limit;
  return (
    <div>
      <div className="flex justify-between text-sm">
        <span className="text-gray-600">{label}</span>
        <span className={`font-semibold ${full ? "text-red-600" : "text-gray-900"}`}>
          {used} / {fmt(limit)}
        </span>
      </div>
      {!unlimited && (
        <div className="h-2 mt-1 rounded-full bg-gray-100 overflow-hidden">
          <div className={`h-full rounded-full ${full ? "bg-red-500" : pct > 75 ? "bg-amber-500" : "bg-brand-500"}`}
               style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  );
}

export default function PlanBilling() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ["plan"],
    queryFn: () => api.get("/plan").then((r) => r.data),
  });

  const [months, setMonths] = useState(12);
  const upgrade = useMutation({
    mutationFn: (plan) => api.post("/plan/upgrade-request", { plan, months }).then((r) => r.data),
    onSuccess: (d) => { toast.success(d.message, { duration: 6000 }); qc.invalidateQueries(["plan"]); },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not send the request."),
  });

  const recordPayment = useMutation({
    mutationFn: ({ id, form }) => {
      const fd = new FormData();
      Object.entries(form).forEach(([k, v]) => v != null && v !== "" && fd.append(k, v));
      return api.post(`/plan/requests/${id}/payment`, fd).then((r) => r.data);
    },
    onSuccess: (d) => { toast.success(d.message, { duration: 6000 }); qc.invalidateQueries(["plan"]); },
    onError: (e) => toast.error(
      Object.values(e.response?.data?.errors ?? {})[0]?.[0] ??
      e.response?.data?.message ?? "Could not record the payment."),
  });

  // Razorpay checkout: load their script on demand, create the order
  // server-side (amount is never client-decided), verify the signature
  // server-side — success activates the licence instantly.
  const payOnline = async (req) => {
    try {
      if (!window.Razorpay) {
        await new Promise((resolve, reject) => {
          const s = document.createElement("script");
          s.src = "https://checkout.razorpay.com/v1/checkout.js";
          s.onload = resolve; s.onerror = () => reject(new Error("gateway script failed"));
          document.body.appendChild(s);
        });
      }
      const order = await api.post(`/plan/requests/${req.id}/razorpay-order`).then((r) => r.data);
      new window.Razorpay({
        key: order.key_id,
        amount: order.amount,
        currency: order.currency,
        name: order.name,
        description: order.description,
        order_id: order.order_id,
        handler: async (resp) => {
          try {
            const v = await api.post(`/plan/requests/${req.id}/razorpay-verify`, resp).then((r) => r.data);
            toast.success(v.message, { duration: 7000 });
            qc.invalidateQueries(["plan"]);
          } catch (e) {
            toast.error(e.response?.data?.message ?? "Verification failed — contact support with your payment id.");
          }
        },
        theme: { color: "#10685A" },
      }).open();
    } catch (e) {
      toast.error(e.response?.data?.message ?? "Could not start online payment — use offline payment below.");
    }
  };

  if (isLoading) return <div className="text-gray-400 p-8">Loading…</div>;
  const org = data?.org;
  const plans = data?.plans ?? {};
  const featureLabels = data?.feature_labels ?? {};
  const pending = data?.pending_request;
  if (!org) return <div className="p-8 text-gray-500">Super admin manages plans on the Subscriptions page.</div>;

  const current = plans[org.plan] ?? {};

  return (
    <div className="space-y-6 max-w-4xl">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Plan &amp; Billing</h1>
        <p className="text-sm text-gray-500 mt-1">{org.name} — payment is settled offline; upgrades are activated by the AMS team.</p>
      </div>

      {/* Current plan + usage */}
      <div className="card">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div>
            <div className="text-xs uppercase tracking-wide text-gray-400 font-semibold">Current plan</div>
            <div className="text-xl font-bold text-gray-900">{current.label ?? org.plan}</div>
            <div className="text-sm text-brand-700 font-medium">{current.price}</div>
          </div>
          {pending && (
            <span className={`badge ${pending.paid_at ? "badge-green" : "badge-yellow"} inline-flex items-center gap-1.5`}>
              <Clock size={13} />
              {pending.paid_at
                ? `Payment recorded — awaiting verification for ${plans[pending.requested_plan]?.label}`
                : `Upgrade to ${plans[pending.requested_plan]?.label} pending`}
            </span>
          )}
        </div>
        {org.plan !== "trial" && org.plan_expires_at && (
          <div className={`mt-3 rounded-lg px-3 py-2 text-sm font-medium ${
            org.licence_lapsed ? "bg-red-50 text-red-700 border border-red-200"
            : org.days_left <= 7 ? "bg-amber-50 text-amber-800 border border-amber-200"
            : "bg-emerald-50 text-emerald-800 border border-emerald-200"}`}>
            {org.licence_lapsed
              ? `Licence EXPIRED on ${org.plan_expires_at} — trial limits apply. Renew below to restore ${org.plan}.`
              : `Licence valid till ${org.plan_expires_at} (${org.days_left} days left).`}
          </div>
        )}
        <div className="grid sm:grid-cols-3 gap-5 mt-5">
          <Meter label="User logins" used={org.usage.users} limit={org.limits.users} />
          {org.usage.workers !== null && <Meter label="Workers" used={org.usage.workers} limit={org.limits.workers} />}
          <Meter label="Company–contractor links" used={org.usage.links} limit={org.limits.links} />
        </div>
      </div>

      {/* Licence period for new purchases/renewals */}
      {!pending && (
        <div className="flex items-center gap-3 text-sm">
          <span className="text-gray-600 font-medium">Licence period:</span>
          {[1, 3, 6, 12].map((m) => (
            <button key={m}
              className={`px-3 py-1 rounded-full border text-sm font-semibold ${
                months === m ? "bg-brand-600 text-white border-brand-600" : "border-gray-300 text-gray-600 hover:border-brand-400"}`}
              onClick={() => setMonths(m)}>
              {m} month{m > 1 ? "s" : ""}
            </button>
          ))}
        </div>
      )}

      {/* Plan cards */}
      <div className="grid md:grid-cols-3 gap-4">
        {ORDER.map((key) => {
          const p = plans[key] ?? {};
          const isCurrent = org.plan === key;
          const isUpgrade = ORDER.indexOf(key) > ORDER.indexOf(org.plan);
          const isRenewal = isCurrent && key !== "trial" && !!org.plan_expires_at;
          return (
            <div key={key} className={`card relative ${isCurrent ? "ring-2 ring-brand-500" : ""}`}>
              {isCurrent && <span className="absolute -top-2.5 left-1/2 -translate-x-1/2 badge badge-green text-[11px]">Your plan</span>}
              <div className="font-bold text-gray-900">{p.label}</div>
              <div className="text-sm text-brand-700 font-semibold mt-0.5">{p.price}</div>
              <ul className="mt-3 space-y-1.5 text-sm text-gray-600">
                <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {fmt(p.users)} user logins</li>
                <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {fmt(p.workers)} workers</li>
                <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {fmt(p.links)} company–vendor links</li>
                <li className="flex gap-2"><Check size={15} className="text-brand-600 shrink-0 mt-0.5" /> {p.support}</li>
                {(p.features ?? []).map((f) => (
                  <li key={f} className="flex gap-2 text-[13px]"><Check size={14} className="text-teal-500 shrink-0 mt-0.5" /> {featureLabels[f] ?? f}</li>
                ))}
              </ul>
              {(isUpgrade || isRenewal) && !pending && (
                <button className="btn-primary w-full justify-center mt-4"
                        disabled={upgrade.isPending}
                        onClick={() => upgrade.mutate(key)}>
                  <ArrowUpCircle size={15} /> {isRenewal ? `Renew ${months} month${months > 1 ? "s" : ""}` : "Request upgrade"}
                </button>
              )}
            </div>
          );
        })}
      </div>

      {/* Offline payment — record it here; the platform team verifies */}
      {pending && (
        <div className="card">
          <h2 className="font-semibold text-gray-900 mb-1">Pay for your upgrade (offline)</h2>
          <p className="text-sm text-gray-500 mb-3">{data?.payment?.note}</p>
          <div className="grid sm:grid-cols-2 gap-3 text-sm mb-4">
            <div className="bg-gray-50 rounded-lg p-3">
              <p className="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">UPI</p>
              <p className="font-mono font-medium text-gray-800">{data?.payment?.upi}</p>
            </div>
            <div className="bg-gray-50 rounded-lg p-3">
              <p className="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Bank transfer</p>
              <p className="font-medium text-gray-800">{data?.payment?.bank}</p>
            </div>
          </div>

          {pending.paid_at ? (
            <div className="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-sm text-emerald-800">
              Payment recorded: <b>{String(pending.payment_method).replace("_", " ")}</b> · ₹{pending.amount} ·
              ref <b>{pending.payment_reference}</b>
              {pending.has_payment_proof && " · proof attached"} — awaiting verification by the platform team.
            </div>
          ) : (
            <>
              {/* Online payment — appears only when the gateway is configured */}
              {data?.razorpay?.enabled && data?.prices_inr?.[pending.requested_plan] && (
                <div className="mb-4 flex items-center gap-3">
                  <button className="btn-primary" onClick={() => payOnline(pending)}>
                    Pay online — ₹{(data.prices_inr[pending.requested_plan] * (pending.months ?? 1)).toLocaleString("en-IN")}
                  </button>
                  <span className="text-xs text-gray-400">UPI / card / netbanking via Razorpay — activates instantly. Or pay offline below.</span>
                </div>
              )}
              <PaymentForm
                onSubmit={(form) => recordPayment.mutate({ id: pending.id, form })}
                busy={recordPayment.isPending}
              />
            </>
          )}
        </div>
      )}
    </div>
  );
}

/** Method + reference + amount + optional proof — the offline-payment record. */
function PaymentForm({ onSubmit, busy }) {
  const [form, setForm] = useState({ payment_method: "upi", payment_reference: "", amount: "", proof: null });
  return (
    <div className="grid sm:grid-cols-4 gap-3 items-end">
      <div>
        <label className="label">Paid via</label>
        <select className="input" value={form.payment_method}
          onChange={(e) => setForm((f) => ({ ...f, payment_method: e.target.value }))}>
          <option value="upi">UPI</option>
          <option value="bank_transfer">Bank transfer</option>
          <option value="cash">Cash</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
      <div>
        <label className="label">Reference / UTR *</label>
        <input className="input" value={form.payment_reference} placeholder="Txn / UTR / cheque no"
          onChange={(e) => setForm((f) => ({ ...f, payment_reference: e.target.value }))} />
      </div>
      <div>
        <label className="label">Amount (₹) *</label>
        <input className="input" type="number" min="1" value={form.amount}
          onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} />
      </div>
      <div>
        <label className="label">Proof (screenshot/PDF)</label>
        <input className="input" type="file" accept="image/*,.pdf"
          onChange={(e) => setForm((f) => ({ ...f, proof: e.target.files?.[0] ?? null }))} />
      </div>
      <div className="sm:col-span-4">
        <button className="btn-primary" disabled={busy || !form.payment_reference || !form.amount}
          onClick={() => onSubmit(form)}>
          Record payment
        </button>
      </div>
    </div>
  );
}
