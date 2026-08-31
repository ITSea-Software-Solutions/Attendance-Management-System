import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import toast from "react-hot-toast";
import { Building2, HardHat, BadgeCheck, X as XIcon } from "lucide-react";
import api from "@/lib/axios";

/**
 * Subscriptions (super admin) — every org's plan + usage, pending upgrade
 * requests to approve/reject (offline payment settled outside the app),
 * and direct plan changes for manual enrolment.
 */
export default function Subscriptions() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ["subscriptions"],
    queryFn: () => api.get("/admin/subscriptions").then((r) => r.data),
  });

  const refresh = () => qc.invalidateQueries(["subscriptions"]);

  const setPlan = useMutation({
    mutationFn: (v) => api.post("/admin/subscriptions/set-plan", v).then((r) => r.data),
    onSuccess: (d) => { toast.success(d.message); refresh(); },
    onError: (e) => toast.error(e.response?.data?.message ?? "Failed to set plan."),
  });
  const decide = useMutation({
    mutationFn: ({ id, action }) => api.post(`/admin/plan-requests/${id}/decide`, { action }).then((r) => r.data),
    onSuccess: (d) => { toast.success(d.message); refresh(); },
    onError: (e) => toast.error(e.response?.data?.message ?? "Failed."),
  });

  if (isLoading) return <div className="text-gray-400 p-8">Loading…</div>;
  const orgs = data?.orgs ?? [];
  const pending = data?.pending_requests ?? [];
  const plans = data?.plans ?? {};
  const fmt = (v) => (v === null || v === undefined ? "∞" : v);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Subscriptions</h1>
        <p className="text-sm text-gray-500 mt-1">
          All organisations, their plans and usage. Approve upgrades after offline payment, or set a plan directly.
        </p>
      </div>

      {pending.length > 0 && (
        <div className="card border-amber-300 bg-amber-50">
          <h2 className="font-semibold text-amber-900 mb-3">Pending upgrade requests ({pending.length})</h2>
          <div className="space-y-2">
            {pending.map((r) => (
              <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 bg-white rounded-lg border border-amber-200 px-3 py-2">
                <div className="text-sm">
                  <span className="font-semibold capitalize">{r.org_type}</span> #{r.org_id} ·{" "}
                  <span className="font-medium">{plans[r.current_plan]?.label}</span> → <span className="font-bold text-brand-700">{plans[r.requested_plan]?.label}</span>
                  <span className="text-gray-500"> · {r.months ?? 1} mo</span>
                  <span className="text-gray-400"> · by {r.requester?.name} ({r.requester?.email})</span>
                  {r.note && <span className="text-gray-400"> · “{r.note}”</span>}
                  {r.paid_at ? (
                    <div className="mt-1 text-xs text-emerald-700 font-medium">
                      💰 {String(r.payment_method).replace("_", " ").toUpperCase()} · ₹{r.amount} · ref {r.payment_reference}
                      {r.has_payment_proof && (
                        <button
                          className="ml-2 underline text-brand-700"
                          onClick={async () => {
                            const resp = await api.get(`/plan/requests/${r.id}/proof`, { responseType: "blob" });
                            window.open(URL.createObjectURL(resp.data), "_blank");
                          }}>
                          view proof
                        </button>
                      )}
                    </div>
                  ) : (
                    <div className="mt-1 text-xs text-amber-700">No payment recorded yet</div>
                  )}
                </div>
                <div className="flex gap-2">
                  <button className="btn-primary" onClick={() => decide.mutate({ id: r.id, action: "approve" })}>
                    <BadgeCheck size={14} /> {r.paid_at ? "Verify payment & activate" : "Approve"}
                  </button>
                  <button className="btn-danger" onClick={() => decide.mutate({ id: r.id, action: "reject" })}>
                    <XIcon size={14} /> Reject
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="card overflow-x-auto p-0">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-gray-400 border-b">
              <th className="px-4 py-3">Organisation</th>
              <th className="px-4 py-3">Plan</th>
              <th className="px-4 py-3">Users</th>
              <th className="px-4 py-3">Workers</th>
              <th className="px-4 py-3">Links</th>
              <th className="px-4 py-3">Since</th>
              <th className="px-4 py-3">Change plan</th>
            </tr>
          </thead>
          <tbody>
            {orgs.map((o) => (
              <tr key={`${o.org_type}-${o.id}`} className="border-b last:border-0 hover:bg-gray-50">
                <td className="px-4 py-2.5">
                  <div className="flex items-center gap-2">
                    {o.org_type === "company"
                      ? <Building2 size={15} className="text-brand-600" />
                      : <HardHat size={15} className="text-amber-600" />}
                    <div>
                      <div className="font-medium text-gray-900">{o.name}</div>
                      <div className="text-xs text-gray-400 font-mono">{o.code} · {o.org_type}</div>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-2.5">
                  <span className={`badge ${o.plan === "enterprise" ? "badge-green" : o.plan === "professional" ? "badge-yellow" : "badge-gray"}`}>
                    {plans[o.plan]?.label ?? o.plan}
                  </span>
                </td>
                <td className="px-4 py-2.5 tabular-nums">{o.usage.users} / {fmt(o.limits.users)}</td>
                <td className="px-4 py-2.5 tabular-nums">{o.usage.workers ?? "—"}{o.usage.workers !== null ? ` / ${fmt(o.limits.workers)}` : ""}</td>
                <td className="px-4 py-2.5 tabular-nums">{o.usage.links} / {fmt(o.limits.links)}</td>
                <td className="px-4 py-2.5 text-gray-500">{o.plan_started_at ?? "—"}</td>
                <td className="px-4 py-2.5">
                  {o.plan !== "trial" && (
                    <div className={`text-[11px] mb-1 font-medium ${o.licence_lapsed ? "text-red-600" : (o.days_left != null && o.days_left <= 7) ? "text-amber-600" : "text-gray-400"}`}>
                      {o.licence_lapsed ? `EXPIRED ${o.plan_expires_at}` : o.plan_expires_at ? `till ${o.plan_expires_at} (${o.days_left}d)` : "no expiry"}
                    </div>
                  )}
                  <select className="input py-1.5 text-sm w-36" value={o.plan}
                          onChange={(e) => {
                            const plan = e.target.value;
                            let months = null;
                            if (plan !== "trial") {
                              const v = window.prompt("Licence period in months (leave empty for NO expiry — e.g. partner/grandfathered):", "12");
                              if (v === null) return;
                              months = v.trim() === "" ? null : parseInt(v, 10) || 12;
                            }
                            setPlan.mutate({ org_type: o.org_type, org_id: o.id, plan, months });
                          }}>
                    <option value="trial">Trial</option>
                    <option value="professional">Professional</option>
                    <option value="enterprise">Enterprise</option>
                  </select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
