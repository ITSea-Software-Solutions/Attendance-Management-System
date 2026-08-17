import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import { Plus, Search, Fingerprint, Download, FileText, Upload } from "lucide-react";
import toast from "react-hot-toast";

const STATUS_BADGE = {
  active:   "badge-green",
  pending:  "badge-yellow",
  inactive: "badge-gray",
  blocked:  "badge-red",
};

export default function WorkerList() {
  const { user }             = useAuth();
  const queryClient          = useQueryClient();
  const navigate             = useNavigate();
  const [search, setSearch]  = useState("");
  const [status, setStatus]  = useState("");
  const [page, setPage]      = useState(1);
  // Default to CURRENT deployments — "who is on shift now" is the everyday view.
  const [tab, setTab]        = useState("current"); // all | current | previous
  const [vendorId, setVendorId] = useState("");
  const [inside, setInside]     = useState(false);       // last event today = IN
  const [presentToday, setPresentToday] = useState(false); // any event today

  const deploymentParam = tab !== "all" ? tab : undefined;

  const { data, isLoading } = useQuery({
    queryKey: ["workers", search, status, page, tab, vendorId, inside, presentToday],
    queryFn:  () => api.get("/workers", { params: {
      search, status, page, deployment: deploymentParam,
      vendor_id: vendorId || undefined,
      inside: inside ? 1 : undefined,
      present_today: presentToday ? 1 : undefined,
    } }).then((r) => r.data),
    keepPreviousData: true,
  });

  // Vendor filter options — company users: their approved vendors; super: all.
  const isVendorUser = ["vendor_admin", "vendor_operator"].includes(user?.role);
  const { data: vendorOpts } = useQuery({
    queryKey: ["vendor-options", user?.role, user?.company_id],
    enabled: !isVendorUser,
    queryFn: async () => {
      const r = user?.role === "super_admin"
        ? await api.get("/vendors")
        : await api.get(`/companies/${user.company_id}/vendors`);
      const rows = r.data?.data ?? r.data?.vendors ?? r.data ?? [];
      return (Array.isArray(rows) ? rows : []).map((v) => ({ id: v.id, name: v.name }));
    },
  });

  const canRegister = ["super_admin", "vendor_admin", "vendor_operator"].includes(user?.role);
  const canDelete   = ["super_admin", "vendor_admin"].includes(user?.role);
  const canActivate = ["super_admin", "company_admin", "vendor_admin"].includes(user?.role);

  const downloadDoc = async (workerId, docId, workerName, typeLabel, isAadhaar = false) => {
    try {
      const url = isAadhaar
        ? `/aadhaar/download/${workerId}`
        : `/workers/${workerId}/id-documents/${docId}/download`;
      const r = await api.get(url, { responseType: "blob" });
      const blob = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = blob;
      a.download = `${workerName}_${typeLabel}`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(blob);
    } catch {
      toast.error("Could not download document.");
    }
  };

  const activateMutation = useMutation({
    mutationFn: (id) => api.post(`/workers/${id}/activate`),
    onSuccess:  () => { queryClient.invalidateQueries(["workers"]); toast.success("Worker activated."); },
    onError:    () => toast.error("Failed to activate worker."),
  });

  const deactivateMutation = useMutation({
    mutationFn: (id) => api.post(`/workers/${id}/deactivate`),
    onSuccess:  () => { queryClient.invalidateQueries(["workers"]); toast.success("Worker deactivated."); },
    onError:    () => toast.error("Failed to deactivate worker."),
  });

  const deleteMutation = useMutation({
    mutationFn: (id) => api.delete(`/workers/${id}`),
    onSuccess:  (r) => { queryClient.invalidateQueries(["workers"]); toast.success(r.data?.message ?? "Worker deleted.", { duration: 6000 }); },
    onError:    (e) => toast.error(e.response?.data?.message ?? "Delete failed."),
  });

  const exportCsv = async () => {
    try {
      const r = await api.get("/workers-export", { responseType: "blob" });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = url; a.download = `truecrew-workers-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
    } catch (e) {
      toast.error(e.response?.status === 403
        ? "Bulk export is a Professional/Enterprise feature — see Plan & Billing."
        : "Export failed.");
    }
  };

  const importCsv = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    const fd = new FormData();
    fd.append("file", file);
    try {
      const r = await api.post("/workers-import", fd, { headers: { "Content-Type": "multipart/form-data" } });
      const errs = r.data.errors ?? [];
      toast.success(r.data.message, { duration: 6000 });
      if (errs.length) toast(errs.slice(0, 3).join("\n"), { duration: 8000, icon: "⚠️" });
      queryClient.invalidateQueries(["workers"]);
    } catch (err) {
      toast.error(err.response?.data?.message
        ?? (err.response?.status === 403
          ? "Bulk import is a Professional/Enterprise feature — see Plan & Billing."
          : "Import failed — check the CSV format."));
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Workers</h1>
          <p className="text-gray-500 text-sm mt-0.5">Registered labor / workers</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <button className="btn-secondary text-sm" onClick={exportCsv} title="Professional+ feature">
            <Download size={14} /> Export CSV
          </button>
          {canRegister && (
            <>
              <label className="btn-secondary text-sm cursor-pointer" title="CSV columns: name, aadhaar_number, dob, gender, phone, email — Professional+ feature">
                <Upload size={14} /> Import CSV
                <input type="file" accept=".csv" className="hidden" onChange={importCsv} />
              </label>
              <Link to="/workers/register" className="btn-primary">
                <Plus size={16} />
                Register Worker
              </Link>
            </>
          )}
        </div>
      </div>

      {/* Deployment tabs */}
      <div className="flex gap-1 border-b border-gray-200">
        {[
          { key: "current",  label: "Current (on deployment)" },
          { key: "previous", label: "Previous" },
          { key: "all",      label: "All Workers" },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => { setTab(t.key); setPage(1); }}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t.key
                ? "border-brand-500 text-brand-700"
                : "border-transparent text-gray-500 hover:text-gray-700"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input
            type="text"
            placeholder="Search by name..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="input pl-9"
          />
        </div>
        {!isVendorUser && (vendorOpts?.length ?? 0) > 0 && (
          <select
            value={vendorId}
            onChange={(e) => { setVendorId(e.target.value); setPage(1); }}
            className="input w-auto"
          >
            <option value="">All Vendors</option>
            {vendorOpts.map((v) => (
              <option key={v.id} value={v.id}>{v.name}</option>
            ))}
          </select>
        )}
        <button
          onClick={() => { setInside(!inside); setPage(1); }}
          className={`px-3 py-2 rounded-lg text-sm font-medium border transition-colors ${
            inside ? "bg-green-50 border-green-500 text-green-700" : "border-gray-300 text-gray-500 hover:bg-gray-50"
          }`}
          title="Workers whose last event today is IN — on shift right now"
        >
          ● Inside now
        </button>
        <button
          onClick={() => { setPresentToday(!presentToday); setPage(1); }}
          className={`px-3 py-2 rounded-lg text-sm font-medium border transition-colors ${
            presentToday ? "bg-brand-50 border-brand-500 text-brand-700" : "border-gray-300 text-gray-500 hover:bg-gray-50"
          }`}
          title="Any attendance event today"
        >
          ✓ Present today
        </button>
        <select
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
          className="input w-auto"
        >
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="pending">Pending</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="card">
          {[...Array(5)].map((_, i) => (
            <div key={i} className="flex items-center gap-4 py-4 border-b last:border-0 animate-pulse">
              <div className="w-10 h-10 bg-gray-100 rounded-full" />
              <div className="flex-1 space-y-1">
                <div className="h-4 bg-gray-100 rounded w-1/3" />
                <div className="h-3 bg-gray-100 rounded w-1/4" />
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="card p-0 overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="text-left px-6 py-3 font-medium text-gray-500">Worker</th>
                <th className="text-left px-4 py-3 font-medium text-gray-500 hidden md:table-cell">Vendor</th>
                <th className="text-left px-4 py-3 font-medium text-gray-500 hidden sm:table-cell">Aadhaar</th>
                <th className="text-center px-4 py-3 font-medium text-gray-500">FP</th>
                <th className="text-left px-4 py-3 font-medium text-gray-500 hidden md:table-cell">ID Document</th>
                <th className="text-left px-4 py-3 font-medium text-gray-500">Status</th>
                <th className="text-left px-4 py-3 font-medium text-gray-500">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {data?.data?.length === 0 ? (
                <tr>
                  <td colSpan={7} className="text-center text-gray-400 py-12">
                    No workers found. {canRegister && <Link to="/workers/register" className="text-brand-600 underline">Register one</Link>}
                  </td>
                </tr>
              ) : data?.data?.map((w) => (
                <tr
                  key={w.id}
                  onClick={() => navigate(`/workers/${w.id}`)}
                  className="hover:bg-gray-50/50 transition-colors cursor-pointer"
                >
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <div className="w-9 h-9 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 font-semibold text-sm flex-shrink-0">
                        {w.name[0]}
                      </div>
                      <div>
                        <p className="font-medium text-gray-900">{w.name}</p>
                        <p className="text-xs text-gray-400">{w.gender === "M" ? "Male" : w.gender === "F" ? "Female" : "Other"}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-4 text-gray-600 hidden md:table-cell">{w.vendor?.name ?? "—"}</td>
                  <td className="px-4 py-4 text-gray-500 font-mono text-xs hidden sm:table-cell">
                    {w.aadhaar_number_masked ?? <span className="text-gray-300">Not uploaded</span>}
                  </td>
                  <td className="px-4 py-4 text-center">
                    {w.fingerprint_enrolled_at
                      ? <Fingerprint size={16} className="text-green-500 mx-auto" title="Enrolled" />
                      : <Fingerprint size={16} className="text-gray-200 mx-auto" title="Not enrolled" />
                    }
                  </td>
                  <td className="px-4 py-4 hidden md:table-cell" onClick={(e) => e.stopPropagation()}>
                    {(() => {
                      const doc = w.id_documents?.find(d => d.is_primary) ?? w.id_documents?.[0];
                      if (!doc) return <span className="text-gray-300 text-xs">—</span>;

                      const isAadhaar = doc.id_type === "aadhaar";
                      const hasFile   = isAadhaar ? w.has_aadhaar_pdf : doc.has_document;

                      const handleDownload = isAadhaar
                        ? () => downloadDoc(w.id, null, w.name, "Aadhaar", true)
                        : () => downloadDoc(w.id, doc.id, w.name, doc.type_label, false);

                      return (
                        <div>
                          <p className="text-xs text-gray-700 font-medium">{doc.type_label}</p>
                          {hasFile ? (
                            <button
                              type="button"
                              onClick={handleDownload}
                              className="flex items-center gap-1 text-xs text-brand-600 hover:text-brand-800 mt-0.5"
                            >
                              <Download size={11} /><FileText size={11} />
                              {isAadhaar ? "Download PDF" : "Download"}
                            </button>
                          ) : (
                            <span className="text-xs text-gray-400">No file</span>
                          )}
                        </div>
                      );
                    })()}
                  </td>
                  <td className="px-4 py-4">
                    <span className={`badge ${STATUS_BADGE[w.status] ?? "badge-gray"}`}>
                      {w.status}
                    </span>
                  </td>
                  <td className="px-4 py-4" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-center gap-2">
                      {canRegister && (
                        <Link to={`/workers/${w.id}/edit`} className="text-xs text-brand-600 hover:underline">
                          Edit
                        </Link>
                      )}
                      {canActivate && w.status === "pending" && (
                        <button
                          onClick={() => activateMutation.mutate(w.id)}
                          disabled={activateMutation.isPending}
                          className="text-xs text-green-600 hover:underline disabled:opacity-50"
                        >
                          Activate
                        </button>
                      )}
                      {canActivate && w.status === "active" && (
                        <button
                          onClick={() => deactivateMutation.mutate(w.id)}
                          disabled={deactivateMutation.isPending}
                          className="text-xs text-red-500 hover:underline disabled:opacity-50"
                        >
                          Deactivate
                        </button>
                      )}
                      {canActivate && w.status === "inactive" && (
                        <button
                          onClick={() => activateMutation.mutate(w.id)}
                          disabled={activateMutation.isPending}
                          className="text-xs text-green-600 hover:underline disabled:opacity-50"
                        >
                          Activate
                        </button>
                      )}
                      {canDelete && (
                        <button
                          onClick={() => {
                            if (window.confirm(`Delete ${w.name}? Attendance history is kept; plan usage counts only workers who actually worked.`)) {
                              deleteMutation.mutate(w.id);
                            }
                          }}
                          disabled={deleteMutation.isPending}
                          className="text-xs text-red-600 hover:underline disabled:opacity-50"
                        >
                          Delete
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Pagination */}
          {data?.last_page > 1 && (
            <div className="flex items-center justify-between px-6 py-4 border-t border-gray-100">
              <p className="text-xs text-gray-500">
                Showing {data.from}–{data.to} of {data.total}
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(p - 1, 1))}
                  disabled={page === 1}
                  className="btn-secondary py-1 text-xs"
                >
                  Prev
                </button>
                <button
                  onClick={() => setPage((p) => Math.min(p + 1, data.last_page))}
                  disabled={page === data.last_page}
                  className="btn-secondary py-1 text-xs"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
