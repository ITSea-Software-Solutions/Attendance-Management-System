import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import { format } from "date-fns";
import {
  ArrowLeft, Building2, Users, CalendarRange, Clock, ShieldCheck,
  ShieldAlert, Phone, Mail, MapPin, FileText, LogIn,
} from "lucide-react";

const APPROVAL_BADGE = {
  approved: "badge-green",
  pending:  "badge-yellow",
  rejected: "badge-red",
};
const STATUS_BADGE = {
  approved:  "badge-green",
  pending:   "badge-yellow",
  rejected:  "badge-red",
  suspended: "badge-gray",
};

const fmtD  = (v) => (v ? format(new Date(v), "dd MMM yyyy") : "—");
const fmtDT = (v) => (v ? format(new Date(v), "dd MMM yyyy, hh:mm a") : "—");

function Stat({ label, value, sub }) {
  return (
    <div className="card px-4 py-3">
      <p className="text-2xl font-bold text-gray-900">{value ?? "—"}</p>
      <p className="text-xs text-gray-500">{label}</p>
      {sub && <p className="text-[11px] text-gray-400 mt-0.5">{sub}</p>}
    </div>
  );
}

function Field({ icon: Icon, label, value }) {
  return (
    <div>
      <p className="text-[11px] uppercase tracking-wide text-gray-400 flex items-center gap-1">
        {Icon && <Icon size={11} />} {label}
      </p>
      <p className="font-medium text-gray-800 mt-0.5 break-words">{value || "—"}</p>
    </div>
  );
}

/**
 * Vendor detail for COMPANY users — profile, relationship, and the vendor's
 * working history with THIS company. History tabs require the vendor's
 * details-sharing consent (collected with the access request).
 */
export default function VendorDetail() {
  const { id }   = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [tab, setTab] = useState("overview");

  const { data, isLoading, error } = useQuery({
    queryKey: ["vendor-detail", user?.company_id, id],
    queryFn:  () => api.get(`/companies/${user.company_id}/vendors/${id}/detail`).then(r => r.data),
    enabled:  !!user?.company_id && !!id,
  });

  const p = data?.profile ?? {};
  const r = data?.relationship ?? {};
  const s = data?.stats ?? {};

  if (error) {
    return (
      <div className="card text-center py-12 text-gray-400">
        {error.response?.status === 404
          ? "No relationship with this vendor."
          : "Could not load vendor details."}
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-wrap items-center gap-3">
        <button onClick={() => navigate("/vendors")} className="btn-secondary px-2.5">
          <ArrowLeft size={16} />
        </button>
        <div className="w-11 h-11 bg-brand-100 rounded-xl flex items-center justify-center">
          <Building2 size={20} className="text-brand-600" />
        </div>
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-gray-900 truncate">{p.name ?? "Vendor"}</h1>
          <div className="flex flex-wrap items-center gap-2 mt-0.5">
            {r.status && <span className={`badge text-xs ${STATUS_BADGE[r.status] ?? "badge-gray"}`}>{r.status}</span>}
            {data?.consented ? (
              <span className="badge badge-green text-xs"><ShieldCheck size={10} className="mr-0.5 inline" />details shared</span>
            ) : data ? (
              <span className="badge badge-gray text-xs"><ShieldAlert size={10} className="mr-0.5 inline" />no consent</span>
            ) : null}
          </div>
        </div>
      </div>

      {/* Not consented — minimal view */}
      {data && !data.consented && (
        <div className="card bg-amber-50 border-amber-200 text-sm text-amber-800">
          {data.message} Their profile and history unlock once they re-request access
          with consent, or when you create the vendor yourself.
        </div>
      )}

      {/* Tabs */}
      {data?.consented && (
        <>
          <div className="flex gap-1 border-b border-gray-200">
            {[
              ["overview", "Overview"],
              ["deployments", "Workers & Deployments"],
              ["attendance", "Attendance History"],
            ].map(([k, label]) => (
              <button
                key={k}
                onClick={() => setTab(k)}
                className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                  tab === k
                    ? "border-brand-600 text-brand-700"
                    : "border-transparent text-gray-500 hover:text-gray-700"
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          {isLoading && <div className="card animate-pulse h-40 bg-gray-100" />}

          {/* ── Overview ─────────────────────────────────────────────── */}
          {!isLoading && tab === "overview" && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Stat label="Workers ever deployed here" value={s.workers_ever_deployed} />
                <Stat label="Active deployments today" value={s.active_deployments} />
                <Stat label="Currently inside" value={s.currently_inside} />
                <Stat label="Total man-days with you" value={s.total_mandays} sub={`${s.mandays_30d ?? 0} in last 30 days`} />
              </div>
              <div className="card">
                <h2 className="font-semibold text-gray-900 mb-3">Organisation profile</h2>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                  <Field icon={Users}  label="Contact person" value={p.contact_person} />
                  <Field icon={Phone}  label="Phone" value={p.contact_phone} />
                  <Field icon={Mail}   label="Email" value={p.contact_email} />
                  <Field icon={MapPin} label="Location" value={[p.city, p.state].filter(Boolean).join(", ")} />
                  <Field icon={FileText} label="GST" value={p.gst_number} />
                  <Field icon={FileText} label="PAN" value={p.pan_number} />
                  <Field label="Contractor code" value={p.code} />
                  <Field label="On TrueCrew since" value={fmtD(p.since)} />
                  <Field label="Org status" value={p.status} />
                </div>
              </div>
              <div className="card">
                <h2 className="font-semibold text-gray-900 mb-3">Relationship with your company</h2>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                  <Field icon={Clock} label="Access requested" value={fmtDT(r.requested_at)} />
                  <Field icon={Clock} label="Approved" value={fmtDT(r.approved_at)} />
                  <Field icon={ShieldCheck} label="Details consent" value={fmtDT(r.details_consent_at)} />
                  <Field icon={LogIn} label="First attendance" value={fmtDT(s.first_attendance)} />
                </div>
                {r.rejection_reason && (
                  <p className="text-xs text-red-600 mt-3 bg-red-50 rounded px-2 py-1">
                    Last rejection reason: {r.rejection_reason}
                  </p>
                )}
              </div>
            </div>
          )}

          {/* ── Deployments ──────────────────────────────────────────── */}
          {!isLoading && tab === "deployments" && (
            <div className="space-y-4">
              <div className="grid grid-cols-3 gap-3">
                <Stat label="Workers ever deployed" value={s.workers_ever_deployed} />
                <Stat label="Active today" value={s.active_deployments} />
                <Stat label="Awaiting your approval" value={s.pending_approvals} />
              </div>
              <div className="card p-0 overflow-hidden">
                <div className="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
                  <CalendarRange size={15} className="text-gray-400" />
                  <h2 className="font-semibold text-gray-900 text-sm">Recent deployments (latest 30)</h2>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                      <tr>
                        <th className="px-4 py-2">Worker</th>
                        <th className="px-4 py-2">Period</th>
                        <th className="px-4 py-2">Approval</th>
                        <th className="px-4 py-2">Requested</th>
                        <th className="px-4 py-2">Decided</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                      {(data.deployments ?? []).map((d) => (
                        <tr
                          key={d.id}
                          className="hover:bg-gray-50/60 cursor-pointer"
                          onClick={() => navigate(`/workers/${d.worker_id}`)}
                        >
                          <td className="px-4 py-2.5 font-medium text-gray-900">{d.worker_name}</td>
                          <td className="px-4 py-2.5 text-gray-600 whitespace-nowrap">
                            {fmtD(d.start_date)} → {fmtD(d.end_date)}
                          </td>
                          <td className="px-4 py-2.5">
                            <span className={`badge text-xs ${APPROVAL_BADGE[d.approval_status] ?? "badge-gray"}`}>
                              {d.approval_status}
                            </span>
                            {d.status !== "active" && (
                              <span className="badge badge-gray text-xs ml-1">{d.status}</span>
                            )}
                          </td>
                          <td className="px-4 py-2.5 text-gray-400 text-xs whitespace-nowrap">{fmtD(d.requested_at)}</td>
                          <td className="px-4 py-2.5 text-gray-400 text-xs whitespace-nowrap">{fmtD(d.decided_at)}</td>
                        </tr>
                      ))}
                      {!(data.deployments ?? []).length && (
                        <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-400">No deployments yet.</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}

          {/* ── Attendance history ───────────────────────────────────── */}
          {!isLoading && tab === "attendance" && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Stat label="Total man-days" value={s.total_mandays} />
                <Stat label="Man-days, last 30d" value={s.mandays_30d} />
                <Stat label="Currently inside" value={s.currently_inside} />
                <Stat label="Last attendance" value={s.last_attendance ? fmtD(s.last_attendance) : "—"} sub={s.first_attendance ? `first: ${fmtD(s.first_attendance)}` : null} />
              </div>
              <div className="card p-0 overflow-hidden">
                <div className="px-5 py-3 border-b border-gray-100">
                  <h2 className="font-semibold text-gray-900 text-sm">Recent activity (last 14 active days)</h2>
                </div>
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr>
                      <th className="px-4 py-2">Date</th>
                      <th className="px-4 py-2">Workers present</th>
                      <th className="px-4 py-2">IN/OUT events</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {(data.daily ?? []).map((row) => (
                      <tr key={row.d} className="hover:bg-gray-50/60">
                        <td className="px-4 py-2.5 font-medium text-gray-900">{fmtD(row.d)}</td>
                        <td className="px-4 py-2.5 text-gray-600">{row.workers}</td>
                        <td className="px-4 py-2.5 text-gray-600">{row.events}</td>
                      </tr>
                    ))}
                    {!(data.daily ?? []).length && (
                      <tr><td colSpan={3} className="px-4 py-8 text-center text-gray-400">No attendance yet.</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
