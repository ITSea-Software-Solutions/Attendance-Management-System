import { useState, useEffect } from "react";
import { useParams, Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import { format } from "date-fns";
import toast from "react-hot-toast";
import {
  ArrowLeft, Calendar, LogIn, LogOut, MapPin, Building2,
  Fingerprint, User, Clock, ChevronDown,
} from "lucide-react";

const STATUS_BADGE = {
  active:   "badge-green",
  pending:  "badge-yellow",
  inactive: "badge-gray",
  blocked:  "badge-red",
};

const DEPLOYMENT_COLORS = {
  active:    "badge-green",
  cancelled: "badge-red",
  completed: "badge-gray",
};

function StatCard({ label, value, icon: Icon, colorClass }) {
  return (
    <div className="card flex items-center gap-4 py-4">
      <div className={`w-11 h-11 rounded-xl flex items-center justify-center ${colorClass} flex-shrink-0`}>
        <Icon size={20} className="text-white" />
      </div>
      <div>
        <p className="text-2xl font-bold text-gray-900">{value}</p>
        <p className="text-xs text-gray-500">{label}</p>
      </div>
    </div>
  );
}

const SKELETON_ROWS = ["a", "b", "c", "d", "e"];

export default function WorkerDetail() {
  const { id }   = useParams();
  const { user } = useAuth();

  const isCompanyUser = ["company_admin", "company_gate"].includes(user?.role);
  const isVendorUser  = ["vendor_admin", "vendor_operator"].includes(user?.role);

  // Company filter — company users are always fixed to their own company
  const [companyId, setCompanyId]       = useState(null); // null = all
  const [companyOptions, setCompanyOptions] = useState(null); // null = not yet loaded

  const params = {};
  if (companyId) params.company_id = companyId;

  const { data, isLoading, isError } = useQuery({
    queryKey: ["worker-stats", id, companyId],
    queryFn:  () => api.get(`/workers/${id}/stats`, { params }).then((r) => r.data),
  });

  // Populate company dropdown options once from the first unfiltered load
  useEffect(() => {
    if (companyOptions === null && !companyId && data?.deployments?.length) {
      const seen = new Map();
      data.deployments.forEach((d) => {
        if (d.company_id && d.company?.name) {
          seen.set(d.company_id, { id: d.company_id, name: d.company.name });
        }
      });
      if (seen.size) setCompanyOptions([...seen.values()]);
    }
  }, [data, companyId, companyOptions]);

  const { worker: workerStats, summary, monthly, deployments, recent_logs } = data ?? {};

  // Full record (verification fields, PDF flag) + manual verify actions.
  const { data: workerFull, refetch: refetchWorker } = useQuery({
    queryKey: ["worker-record", id],
    queryFn:  () => api.get(`/workers/${id}`).then((r) => r.data),
  });
  const worker = workerFull ?? workerStats;
  const canVerify = ["super_admin", "vendor_admin"].includes(user?.role);
  const verifyStep = async (step) => {
    try {
      const r = await api.post(`/workers/${id}/verify-step`, { step });
      toast.success(r.data.message);
      refetchWorker();
    } catch (e) {
      toast.error(e.response?.data?.message ?? "Could not verify.");
    }
  };

  // Label shown above stats — company name for company users, selected company for vendors
  const scopeLabel = isCompanyUser
    ? user?.company?.name
    : companyId
      ? companyOptions?.find((c) => c.id === companyId)?.name
      : null;

  if (isError) {
    return (
      <div className="space-y-4">
        <Link to="/workers" className="text-sm text-brand-600 hover:underline flex items-center gap-1">
          <ArrowLeft size={14} /> All Workers
        </Link>
        <div className="card text-center py-12 text-gray-400">Could not load worker details.</div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-5xl">

      {/* Back */}
      <Link to="/workers" className="text-sm text-brand-600 hover:underline flex items-center gap-1 w-fit">
        <ArrowLeft size={14} /> All Workers
      </Link>

      {/* Worker header */}
      <div className="card flex flex-col sm:flex-row items-start gap-4">
        <div className="w-16 h-16 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 font-bold text-2xl flex-shrink-0">
          {isLoading ? "?" : (worker?.name?.[0] ?? "?")}
        </div>

        <div className="flex-1 min-w-0">
          {isLoading ? (
            <div className="space-y-2">
              <div className="h-6 bg-gray-100 rounded animate-pulse w-48" />
              <div className="h-4 bg-gray-100 rounded animate-pulse w-32" />
            </div>
          ) : (
            <>
              <h1 className="text-2xl font-bold text-gray-900 truncate">{worker?.name}</h1>
              <p className="text-sm text-gray-500 mt-0.5">{worker?.vendor?.name}</p>
              <div className="flex flex-wrap items-center gap-2 mt-2">
                <span className={`badge ${STATUS_BADGE[worker?.status] ?? "badge-gray"}`}>
                  {worker?.status}
                </span>
                {worker?.fingerprint_enrolled_at && (
                  <span className="badge badge-green text-xs">
                    <Fingerprint size={10} className="mr-1 inline" />Fingerprint Enrolled
                  </span>
                )}
                {worker?.gender && (
                  <span className="text-xs text-gray-400">
                    {worker.gender === "M" ? "Male" : worker.gender === "F" ? "Female" : "Other"}
                  </span>
                )}
                {worker?.aadhaar_number_masked && (
                  <span className="text-xs text-gray-400 font-mono">{worker.aadhaar_number_masked}</span>
                )}
              </div>
            </>
          )}
        </div>

        {worker?.phone && (
          <div className="text-sm text-gray-500 flex items-center gap-1.5 shrink-0">
            <User size={13} className="text-gray-400" />
            {worker.phone}
          </div>
        )}
      </div>

      {/* Verification steps — each identity check, at a glance */}
      {worker && (
        <div className="card">
          <p className="text-sm font-semibold text-gray-800 mb-3">Verification steps</p>
          <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
            {[
              { label: "Aadhaar", done: !!worker.aadhaar_number_masked, hint: worker.has_aadhaar_pdf ? "PDF on file" : "number verified" },
              { label: "Fingerprint", done: !!worker.fingerprint_enrolled_at, hint: worker.fingerprint_enrolled_at ? "enrolled" : "pending" },
              { label: "Face / photo", done: !!worker.face_enrolled_at, hint: worker.face_enrolled_at ? "enrolled" : "pending" },
              { label: "Email", done: !!worker.email_verified_at, hint: worker.email ?? "no email", verifyStep: "email", can: !!worker.email && !worker.email_verified_at },
              { label: "Phone", done: !!worker.phone_verified_at, hint: worker.phone ?? worker.mobile ?? "no phone", verifyStep: "phone", can: !!(worker.phone || worker.mobile) && !worker.phone_verified_at },
            ].map((s) => (
              <div key={s.label} className={`rounded-lg border p-3 ${s.done ? "border-green-200 bg-green-50" : "border-gray-200 bg-gray-50"}`}>
                <div className="flex items-center gap-1.5">
                  <span className={`w-2 h-2 rounded-full ${s.done ? "bg-green-500" : "bg-gray-300"}`} />
                  <span className="text-xs font-semibold text-gray-700">{s.label}</span>
                </div>
                <p className="text-[11px] text-gray-500 mt-1 truncate">{s.done ? "Verified" : s.hint}</p>
                {canVerify && s.can && (
                  <button
                    className="text-[11px] text-brand-600 font-medium mt-1 underline"
                    onClick={() => verifyStep(s.verifyStep)}
                  >
                    Mark verified
                  </button>
                )}
              </div>
            ))}
          </div>
          <p className="text-[11px] text-gray-400 mt-2">
            Aadhaar, fingerprint and face verify through their own flows. Email/phone are attested manually for now — OTP verification activates with an SMS/WhatsApp provider (Enterprise).
          </p>
        </div>
      )}

      {/* Scope bar */}
      <div className="flex items-center gap-3">
        <Building2 size={15} className="text-gray-400 shrink-0" />

        {/* Company users — fixed label */}
        {isCompanyUser && (
          <span className="text-sm font-medium text-gray-700">
            Viewing at <span className="text-brand-700">{scopeLabel ?? "your company"}</span>
          </span>
        )}

        {/* Vendor / super_admin — dropdown */}
        {!isCompanyUser && (
          <div className="relative">
            <select
              value={companyId ?? ""}
              onChange={(e) => setCompanyId(e.target.value ? Number(e.target.value) : null)}
              className="input py-1.5 pr-8 text-sm appearance-none"
            >
              <option value="">All Companies</option>
              {(companyOptions ?? []).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
          </div>
        )}

        {scopeLabel && !isCompanyUser && (
          <span className="text-xs text-gray-400">— showing stats for this company only</span>
        )}
        {!scopeLabel && !isCompanyUser && companyOptions?.length > 0 && (
          <span className="text-xs text-gray-400">— aggregate across all deployments</span>
        )}
      </div>

      {/* Summary stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {isLoading ? (
          SKELETON_ROWS.slice(0, 4).map((k) => (
            <div key={k} className="card h-20 animate-pulse bg-gray-50" />
          ))
        ) : (
          <>
            <StatCard label="Working Days"    value={summary?.total_days ?? 0}          icon={Calendar} colorClass="bg-brand-500" />
            <StatCard label="Total Check IN"  value={summary?.total_in ?? 0}            icon={LogIn}    colorClass="bg-green-500" />
            <StatCard label="Total Check OUT" value={summary?.total_out ?? 0}           icon={LogOut}   colorClass="bg-blue-500"  />
            <StatCard label="Locations"       value={summary?.locations?.length ?? 0}  icon={MapPin}   colorClass="bg-purple-500" />
          </>
        )}
      </div>

      {/* Main content — two columns */}
      <div className={`grid grid-cols-1 gap-5 ${!isCompanyUser ? "lg:grid-cols-2" : ""}`}>

        {/* Recent Attendance */}
        <div className="card p-0 overflow-hidden">
          <div className="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
            <Clock size={15} className="text-gray-400" />
            <h2 className="font-semibold text-gray-900">Recent Attendance</h2>
          </div>
          <div className="divide-y divide-gray-50 max-h-[420px] overflow-y-auto">
            {isLoading && SKELETON_ROWS.map((k) => (
              <div key={k} className="px-5 py-3">
                <div className="h-4 bg-gray-100 rounded animate-pulse w-3/4" />
              </div>
            ))}
            {!isLoading && !recent_logs?.length && (
              <p className="text-center text-gray-400 py-10 text-sm">No attendance records.</p>
            )}
            {!isLoading && recent_logs?.map((log) => (
              <div key={log.id} className="flex items-center justify-between px-5 py-3 hover:bg-gray-50/50">
                <div>
                  <p className="text-sm font-medium text-gray-900">
                    {format(new Date(log.marked_at), "dd MMM yyyy")}
                  </p>
                  <p className="text-xs text-gray-400 mt-0.5 flex items-center gap-1">
                    {log.location_name
                      ? <><MapPin size={10} />{log.location_name}</>
                      : log.company?.name
                        ? <><Building2 size={10} />{log.company.name}</>
                        : null}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <span className={`badge text-xs ${log.type === "IN" ? "badge-green" : "badge-blue"}`}>
                    {log.type === "IN"
                      ? <LogIn size={9} className="mr-0.5 inline" />
                      : <LogOut size={9} className="mr-0.5 inline" />}
                    {log.type}
                  </span>
                  <span className="text-xs text-gray-400 whitespace-nowrap">
                    {format(new Date(log.marked_at), "hh:mm a")}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Deployment History — hidden for company users */}
        {!isCompanyUser && (
          <div className="card p-0 overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
              <Building2 size={15} className="text-gray-400" />
              <h2 className="font-semibold text-gray-900">
                {scopeLabel ? `Deployments at ${scopeLabel}` : "Deployment History"}
              </h2>
            </div>
            <div className="divide-y divide-gray-50 max-h-[420px] overflow-y-auto">
              {isLoading && SKELETON_ROWS.map((k) => (
                <div key={k} className="px-5 py-3">
                  <div className="h-4 bg-gray-100 rounded animate-pulse w-3/4" />
                </div>
              ))}
              {!isLoading && !deployments?.length && (
                <p className="text-center text-gray-400 py-10 text-sm">No deployments found.</p>
              )}
              {!isLoading && deployments?.map((d) => (
                <div key={d.id} className="px-5 py-3 hover:bg-gray-50/50">
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium text-gray-900 flex items-center gap-1.5">
                      <Building2 size={13} className="text-gray-400" />
                      {d.company?.name}
                    </p>
                    <span className={`badge text-xs ${DEPLOYMENT_COLORS[d.status] ?? "badge-gray"}`}>
                      {d.status}
                    </span>
                  </div>
                  <p className="text-xs text-gray-400 mt-1 flex items-center gap-1">
                    <Calendar size={11} />
                    {format(new Date(d.start_date), "dd MMM yyyy")}
                    <span className="mx-0.5">→</span>
                    {format(new Date(d.end_date), "dd MMM yyyy")}
                    {d.shift && d.shift !== "general" && (
                      <span className="ml-2 capitalize">({d.shift})</span>
                    )}
                  </p>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Monthly Summary */}
      {(isLoading || monthly?.length > 0) && (
        <div className="card p-0 overflow-hidden">
          <div className="px-5 py-4 border-b border-gray-100">
            <h2 className="font-semibold text-gray-900">Monthly Summary</h2>
            <p className="text-xs text-gray-400 mt-0.5">Last 6 months</p>
          </div>
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-5 py-3 font-medium text-gray-500">Month</th>
                <th className="text-center px-4 py-3 font-medium text-gray-500">Days Worked</th>
                <th className="text-center px-4 py-3 font-medium text-gray-500">Check IN</th>
                <th className="text-center px-4 py-3 font-medium text-gray-500">Check OUT</th>
                <th className="text-center px-4 py-3 font-medium text-gray-500">Missed OUT</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {isLoading && SKELETON_ROWS.map((k) => (
                <tr key={k}>
                  <td colSpan={5} className="px-5 py-3">
                    <div className="h-4 bg-gray-100 rounded animate-pulse w-2/3" />
                  </td>
                </tr>
              ))}
              {!isLoading && monthly?.map((m) => {
                const missed = m.in_count - m.out_count;
                return (
                  <tr key={m.month} className="hover:bg-gray-50/50">
                    <td className="px-5 py-3 font-medium text-gray-900">
                      {format(new Date(m.month + "-01"), "MMM yyyy")}
                    </td>
                    <td className="px-4 py-3 text-center text-gray-600">{m.days}</td>
                    <td className="px-4 py-3 text-center">
                      <span className="font-medium text-green-600">{m.in_count}</span>
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className="font-medium text-blue-600">{m.out_count}</span>
                    </td>
                    <td className="px-4 py-3 text-center">
                      {missed > 0
                        ? <span className="font-medium text-amber-600">{missed}</span>
                        : <span className="text-gray-300">—</span>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Locations */}
      {!isLoading && summary?.locations?.length > 0 && (
        <div className="card">
          <h2 className="font-semibold text-gray-900 mb-3">Locations / Departments Visited</h2>
          <div className="flex flex-wrap gap-2">
            {summary.locations.map((loc) => (
              <span key={loc} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-sm">
                <MapPin size={12} className="text-gray-400" />
                {loc}
              </span>
            ))}
          </div>
        </div>
      )}

    </div>
  );
}
