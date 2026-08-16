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

  const upgrade = useMutation({
    mutationFn: (plan) => api.post("/plan/upgrade-request", { plan }).then((r) => r.data),
    onSuccess: (d) => { toast.success(d.message, { duration: 6000 }); qc.invalidateQueries(["plan"]); },
    onError: (e) => toast.error(e.response?.data?.message ?? "Could not send the request."),
  });

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
            <span className="badge badge-yellow inline-flex items-center gap-1.5">
              <Clock size={13} /> Upgrade to {plans[pending.requested_plan]?.label} pending — we'll contact you
            </span>
          )}
        </div>
        <div className="grid sm:grid-cols-3 gap-5 mt-5">
          <Meter label="User logins" used={org.usage.users} limit={org.limits.users} />
          {org.usage.workers !== null && <Meter label="Workers" used={org.usage.workers} limit={org.limits.workers} />}
          <Meter label="Company–vendor links" used={org.usage.links} limit={org.limits.links} />
        </div>
      </div>

      {/* Plan cards */}
      <div className="grid md:grid-cols-3 gap-4">
        {ORDER.map((key) => {
          const p = plans[key] ?? {};
          const isCurrent = org.plan === key;
          const isUpgrade = ORDER.indexOf(key) > ORDER.indexOf(org.plan);
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
              {isUpgrade && !pending && (
                <button className="btn-primary w-full justify-center mt-4"
                        disabled={upgrade.isPending}
                        onClick={() => upgrade.mutate(key)}>
                  <ArrowUpCircle size={15} /> Request upgrade
                </button>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
