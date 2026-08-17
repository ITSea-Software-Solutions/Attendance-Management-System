import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import { Building2, CheckCircle, Clock, XCircle, PauseCircle, Send, Search } from "lucide-react";

function StatusBadge({ status }) {
  if (!status) return null;
  const map = {
    pending:   <span className="badge badge-yellow flex items-center gap-1"><Clock size={10} />Pending</span>,
    approved:  <span className="badge badge-green flex items-center gap-1"><CheckCircle size={10} />Approved</span>,
    rejected:  <span className="badge badge-red flex items-center gap-1"><XCircle size={10} />Rejected</span>,
    suspended: <span className="badge badge-gray flex items-center gap-1"><PauseCircle size={10} />Suspended</span>,
  };
  return map[status] ?? <span className="badge badge-gray">{status}</span>;
}

export default function VendorCompanyAccess() {
  const { user }    = useAuth();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [sending, setSending] = useState({});
  const [consent, setConsent] = useState(false);

  const vendorId = user?.vendor_id;

  const { data: companies, isLoading } = useQuery({
    queryKey: ["vendor-available-companies", vendorId],
    queryFn:  () => api.get(`/vendors/${vendorId}/available-companies`).then(r => r.data),
    enabled:  !!vendorId,
  });

  const sendRequest = async (companyId) => {
    if (!consent) {
      toast.error("Please tick the details-sharing consent first.");
      return;
    }
    setSending(s => ({ ...s, [companyId]: true }));
    try {
      await api.post(`/vendors/${vendorId}/request-company/${companyId}`, { consent: true });
      toast.success("Request sent! Waiting for company approval.");
      queryClient.invalidateQueries(["vendor-available-companies", vendorId]);
    } catch (e) {
      toast.error(e.response?.data?.message ?? "Failed to send request.");
    } finally {
      setSending(s => ({ ...s, [companyId]: false }));
    }
  };

  const filtered = (companies ?? []).filter(c =>
    !search || c.name.toLowerCase().includes(search.toLowerCase()) || c.city?.toLowerCase().includes(search.toLowerCase())
  );

  const canRequest = (status) => !status || status === "rejected";

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Company Access</h1>
        <p className="text-sm text-gray-500 mt-1">
          Request access to companies. Once approved, your workers' attendance will be tracked there automatically.
        </p>
      </div>

      {/* How it works */}
      <div className="card bg-brand-50 border-brand-100">
        <p className="text-sm font-semibold text-brand-800 mb-1">How it works</p>
        <ol className="text-sm text-brand-700 space-y-1 list-decimal list-inside">
          <li>Send an access request to the company below</li>
          <li>The company admin reviews and approves your request</li>
          <li>Once approved, the gate user can mark attendance for your workers automatically</li>
          <li>View attendance records in the Attendance Log page</li>
        </ol>
      </div>

      {/* Details-sharing consent — sent with every access request */}
      <label className="card flex items-start gap-3 cursor-pointer">
        <input
          type="checkbox"
          checked={consent}
          onChange={(e) => setConsent(e.target.checked)}
          className="mt-1 h-4 w-4 accent-brand-600"
        />
        <span className="text-sm text-gray-600">
          <span className="font-medium text-gray-800">Details-sharing consent.</span>{" "}
          I agree that companies I request access to may view our organisation profile
          (contact, GST/PAN) and keep track of our working history with them — workers
          supplied, deployments, and attendance — while our access is active. Required
          to send a request.
        </span>
      </label>

      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
        <input
          type="text"
          placeholder="Search companies…"
          value={search}
          onChange={e => setSearch(e.target.value)}
          className="input pl-9"
        />
      </div>

      {isLoading && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="card animate-pulse h-28 bg-gray-100" />
          ))}
        </div>
      )}

      {!isLoading && filtered.length === 0 && (
        <div className="card text-center py-12 text-gray-400">
          <Building2 className="w-10 h-10 mx-auto mb-2 text-gray-200" />
          <p>No companies found.</p>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {filtered.map(c => (
          <div key={c.id} className="card hover:shadow-md transition-shadow">
            <div className="flex items-start justify-between gap-2">
              <div className="flex items-start gap-3 min-w-0">
                <div className="w-10 h-10 bg-brand-100 rounded-xl flex items-center justify-center shrink-0">
                  <Building2 size={18} className="text-brand-600" />
                </div>
                <div className="min-w-0">
                  <p className="font-semibold text-gray-900 truncate">{c.name}</p>
                  <p className="text-xs font-mono text-gray-400">{c.code}</p>
                  <p className="text-xs text-gray-500 mt-0.5">
                    {[c.city, c.state].filter(Boolean).join(", ")}
                  </p>
                </div>
              </div>
              <StatusBadge status={c.request_status} />
            </div>

            {c.request_status === "rejected" && c.rejection_reason && (
              <p className="text-xs text-red-500 mt-2 bg-red-50 rounded px-2 py-1">
                Rejected: {c.rejection_reason}
              </p>
            )}

            {c.request_status === "approved" && c.approved_at && (
              <p className="text-xs text-green-600 mt-2">
                Approved on {new Date(c.approved_at).toLocaleDateString()}
              </p>
            )}

            {c.request_status === "suspended" && (
              <p className="text-xs text-gray-500 mt-2">
                Your access was suspended by this company. Contact them to restore.
              </p>
            )}

            <div className="mt-3 pt-3 border-t border-gray-100">
              {canRequest(c.request_status) ? (
                <button
                  onClick={() => sendRequest(c.id)}
                  disabled={sending[c.id]}
                  className="btn-primary text-sm w-full justify-center"
                >
                  <Send size={14} />
                  {sending[c.id] ? "Sending…" : c.request_status === "rejected" ? "Request Again" : "Request Access"}
                </button>
              ) : c.request_status === "pending" ? (
                <p className="text-xs text-center text-yellow-600">
                  Waiting for company admin to review your request
                </p>
              ) : (
                <p className="text-xs text-center text-green-600 font-medium">
                  Access active — workers can be tracked at this company
                </p>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
