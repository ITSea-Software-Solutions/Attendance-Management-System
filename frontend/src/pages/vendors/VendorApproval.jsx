import { useState } from "react";
import PropTypes from "prop-types";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import { CheckCircle, XCircle, Clock, PauseCircle, PlayCircle, AlertTriangle, Search, X } from "lucide-react";

const STATUS_CFG = {
  pending:       { label: "Pending",       badge: "badge-yellow", Icon: Clock       },
  approved:      { label: "Approved",      badge: "badge-green",  Icon: CheckCircle },
  rejected:      { label: "Rejected",      badge: "badge-red",    Icon: XCircle     },
  suspended:     { label: "Suspended",     badge: "badge-gray",   Icon: PauseCircle },
  not_requested: { label: "Not Requested", badge: "badge-gray",   Icon: null        },
};

const SKELETON_KEYS = ["sk-1", "sk-2", "sk-3", "sk-4", "sk-5"];

function StatusBadge({ status }) {
  const cfg = STATUS_CFG[status] ?? STATUS_CFG.not_requested;
  const { Icon } = cfg;
  return (
    <span className={`badge ${cfg.badge} inline-flex items-center gap-1`}>
      {Icon && <Icon size={10} />}
      {cfg.label}
    </span>
  );
}

StatusBadge.propTypes = {
  status: PropTypes.string.isRequired,
};

export default function VendorApproval() {
  const { user }     = useAuth();
  const queryClient  = useQueryClient();
  const isSuperAdmin = user?.role === "super_admin";

  const [selectedCompanyId, setSelectedCompanyId] = useState(user?.company_id ?? null);
  const [filter, setFilter]   = useState("pending");
  const [search, setSearch]   = useState("");
  const [rejecting, setRejecting]     = useState(null); // vendor object
  const [rejectReason, setRejectReason] = useState("");

  const companyId = isSuperAdmin ? selectedCompanyId : user?.company_id;

  // ── Data fetching ──────────────────────────────────────────────────────────

  const { data: companies } = useQuery({
    queryKey: ["companies-list"],
    queryFn:  () => api.get("/companies").then(r => r.data?.data ?? r.data),
    enabled:  isSuperAdmin,
  });

  // All vendors — gives super_admin full picture including "not requested" ones
  const { data: allVendors } = useQuery({
    queryKey: ["vendors-all"],
    queryFn:  () => api.get("/vendors", { params: { per_page: 200 } }).then(r => r.data?.data ?? r.data),
    enabled:  isSuperAdmin,
  });

  // Vendors that have a relationship with the selected company (includes pivot)
  const { data: companyVendors, isLoading } = useQuery({
    queryKey: ["company-vendors", companyId],
    queryFn:  () => api.get(`/companies/${companyId}/vendors`).then(r => r.data),
    enabled:  !!companyId,
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["company-vendors", companyId] });

  // ── Actions ────────────────────────────────────────────────────────────────

  const act = (vendor, path, body) =>
    api.post(`/companies/${companyId}/vendors/${vendor.id}/${path}`, body ?? {})
      .then(() => { toast.success("Done."); refresh(); })
      .catch((e) => toast.error(e.response?.data?.message ?? "Error"));

  const handleReject = () => {
    if (!rejectReason.trim()) { toast.error("Enter a reason."); return; }
    act(rejecting, "reject", { reason: rejectReason }).then(() => {
      setRejecting(null);
      setRejectReason("");
    });
  };

  // ── Merge all vendors with their per-company status ────────────────────────

  // Super admin: merge all vendors + relationship data so "not requested" rows appear
  // Company admin: only vendors that have made a request (existing behaviour)
  const rows = isSuperAdmin
    ? (allVendors ?? []).map(v => {
        const cv = (companyVendors ?? []).find(cv => cv.id === v.id);
        return { ...v, pivot: cv?.pivot ?? null };
      })
    : (companyVendors ?? []);

  const statusOf = (v) => v.pivot?.status ?? "not_requested";

  // ── Counts for tabs ────────────────────────────────────────────────────────

  const counts = {
    pending:       rows.filter(v => v.pivot?.status === "pending").length,
    approved:      rows.filter(v => v.pivot?.status === "approved").length,
    rejected:      rows.filter(v => v.pivot?.status === "rejected").length,
    suspended:     rows.filter(v => v.pivot?.status === "suspended").length,
    not_requested: rows.filter(v => !v.pivot).length,
  };

  const TABS = [
    { key: "pending",       label: "Pending",       count: counts.pending       },
    { key: "approved",      label: "Approved",      count: counts.approved      },
    { key: "rejected",      label: "Rejected",      count: counts.rejected      },
    { key: "suspended",     label: "Suspended",     count: counts.suspended     },
    ...(isSuperAdmin ? [{ key: "not_requested", label: "Not Requested", count: counts.not_requested }] : []),
    { key: "all",           label: "All",           count: rows.length          },
  ];

  const filtered = rows
    .filter(v => {
      if (filter === "all")           return true;
      if (filter === "not_requested") return !v.pivot;
      return v.pivot?.status === filter;
    })
    .filter(v =>
      !search ||
      v.name?.toLowerCase().includes(search.toLowerCase()) ||
      v.code?.toLowerCase().includes(search.toLowerCase())
    );

  // ── Render ─────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Contractor Approvals</h1>
        <p className="text-sm text-gray-500 mt-0.5">Manage contractor access requests per company</p>
      </div>

      {/* Company selector — super admin only */}
      {isSuperAdmin && (
        <div className="card">
          <label className="label">Select Company</label>
          <select
            className="input max-w-sm"
            value={selectedCompanyId ?? ""}
            onChange={e => {
              setSelectedCompanyId(e.target.value || null);
              setFilter("pending");
              setSearch("");
            }}
          >
            <option value="">— choose a company —</option>
            {(companies ?? []).map(c => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        </div>
      )}

      {!companyId && isSuperAdmin && (
        <div className="card text-center py-12 text-gray-400">
          <p>Select a company above to view its contractor relationships.</p>
        </div>
      )}

      {companyId && (
        <>
          {/* Status tabs */}
          <div className="flex gap-0 flex-wrap border-b border-gray-200">
            {TABS.map(t => (
              <button
                key={t.key}
                onClick={() => setFilter(t.key)}
                className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px ${
                  filter === t.key
                    ? "border-brand-500 text-brand-700"
                    : "border-transparent text-gray-500 hover:text-gray-700"
                }`}
              >
                {t.label}
                {t.count > 0 && (
                  <span className={`ml-1.5 text-xs px-1.5 py-0.5 rounded-full ${
                    filter === t.key ? "bg-brand-100 text-brand-700" : "bg-gray-100 text-gray-500"
                  }`}>
                    {t.count}
                  </span>
                )}
              </button>
            ))}
          </div>

          {/* Search */}
          <div className="relative max-w-xs">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Search by name or code…"
              className="input pl-8 text-sm"
            />
          </div>

          {/* Table */}
          <div className="card p-0 overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100">
                <tr>
                  <th className="text-left px-5 py-3 font-medium text-gray-500">Contractor</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-500 hidden md:table-cell">Contact</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-500 hidden lg:table-cell">Location</th>
                  <th className="text-center px-4 py-3 font-medium text-gray-500">Status</th>
                  <th className="text-right px-5 py-3 font-medium text-gray-500">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {isLoading && SKELETON_KEYS.map(key => (
                  <tr key={key}>
                    <td colSpan={5} className="px-5 py-3">
                      <div className="h-4 bg-gray-100 rounded animate-pulse w-3/4" />
                    </td>
                  </tr>
                ))}

                {!isLoading && filtered.length === 0 && (
                  <tr>
                    <td colSpan={5} className="text-center py-12 text-gray-400">
                      <AlertTriangle size={28} className="mx-auto mb-2 text-gray-200" />
                      No vendors in this category.
                    </td>
                  </tr>
                )}

                {!isLoading && filtered.map(v => {
                  const status = statusOf(v);
                  return (
                    <tr key={v.id} className="hover:bg-gray-50/50">
                      <td className="px-5 py-3">
                        <p className="font-medium text-gray-900">{v.name}</p>
                        <p className="text-xs text-gray-400 font-mono">{v.code}</p>
                      </td>
                      <td className="px-4 py-3 hidden md:table-cell">
                        <p className="text-gray-700">{v.contact_person || "—"}</p>
                        <p className="text-xs text-gray-400">{v.contact_email}</p>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500 hidden lg:table-cell">
                        {[v.city, v.state].filter(Boolean).join(", ") || "—"}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <StatusBadge status={status} />
                      </td>
                      <td className="px-5 py-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          {status === "pending" && (
                            <>
                              <button
                                onClick={() => act(v, "approve")}
                                className="text-xs font-medium text-green-600 hover:text-green-800"
                              >
                                Approve
                              </button>
                              <span className="text-gray-300">|</span>
                              <button
                                onClick={() => { setRejecting(v); setRejectReason(""); }}
                                className="text-xs font-medium text-red-500 hover:text-red-700"
                              >
                                Reject
                              </button>
                            </>
                          )}
                          {status === "approved" && (
                            <button
                              onClick={() => act(v, "suspend")}
                              className="text-xs font-medium text-amber-600 hover:text-amber-800"
                            >
                              Suspend
                            </button>
                          )}
                          {(status === "rejected" || status === "suspended") && (
                            <button
                              onClick={() => act(v, "approve")}
                              className="text-xs font-medium text-green-600 hover:text-green-800"
                            >
                              Re-approve
                            </button>
                          )}
                          {status === "not_requested" && (
                            <span className="text-xs text-gray-300">—</span>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}

      {/* Reject reason modal */}
      {rejecting && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-semibold text-gray-900">Reject — {rejecting.name}</h3>
              <button onClick={() => setRejecting(null)} aria-label="Close">
                <X size={18} className="text-gray-400" />
              </button>
            </div>
            <div>
              <label className="label">Reason for rejection *</label>
              <textarea
                rows={3}
                className="input resize-none"
                placeholder="Enter reason…"
                value={rejectReason}
                onChange={e => setRejectReason(e.target.value)}
              />
            </div>
            <div className="flex gap-2">
              <button onClick={handleReject} className="btn-danger flex-1 justify-center">
                <XCircle size={15} /> Confirm Reject
              </button>
              <button onClick={() => setRejecting(null)} className="btn-secondary">
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
