import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import { Plus, Pencil, Trash2, ToggleLeft, ToggleRight, X, Eye, EyeOff, RefreshCw, Copy, MapPin } from "lucide-react";

const ALL_ROLES = [
  { value: "super_admin",      label: "Super Admin" },
  { value: "company_admin",    label: "Company Admin" },
  { value: "company_gate",     label: "Company Gate" },
  { value: "vendor_admin",     label: "Contractor Admin" },
  { value: "vendor_operator",  label: "Contractor Operator" },
];

const ROLE_BADGE = {
  company_hr: "bg-purple-100 text-purple-700",
  super_admin:     "bg-purple-100 text-purple-700",
  company_admin:   "bg-blue-100 text-blue-700",
  company_gate:    "bg-cyan-100 text-cyan-700",
  vendor_admin:    "bg-orange-100 text-orange-700",
  vendor_operator: "bg-yellow-100 text-yellow-700",
};

// Login-type options for gate/department users
const LOCATION_TYPES = [
  { value: "main_gate",   label: "Gate",       placeholder: "e.g. Gate 1, East Gate, Main Gate" },
  { value: "department",  label: "Department", placeholder: "e.g. Finance, IT, Production" },
  { value: "checkpoint",  label: "Other",      placeholder: "e.g. Canteen, Parking, Building B" },
];

function locationTypeLabel(val) {
  return LOCATION_TYPES.find(t => t.value === val)?.label ?? val;
}

function genPassword() {
  const a = "abcdefghijkmnpqrstuvwxyz", A = "ABCDEFGHJKLMNPQRSTUVWXYZ", n = "23456789";
  const all = a + A + n;
  let p = A[Math.floor(Math.random() * A.length)] + n[Math.floor(Math.random() * n.length)];
  for (let i = 0; i < 8; i++) p += all[Math.floor(Math.random() * all.length)];
  return p.split("").sort(() => Math.random() - 0.5).join("");
}

const EMPTY = {
  name: "", email: "", password: "", role: "", company_id: "", vendor_id: "",
  phone: "", location_type: "", location_name: "",
};

export default function UserList() {
  const { user: authUser } = useAuth();
  const qc = useQueryClient();
  const [modal, setModal]       = useState(null);
  const [form, setForm]         = useState(EMPTY);
  const [showPass, setShowPass] = useState(false);

  const isCompanyAdmin = authUser?.role === "company_admin";
  const isVendorAdmin  = authUser?.role === "vendor_admin";

  const availableRoles = isCompanyAdmin
    ? [
        { value: "company_gate", label: "Gate user (marks IN/OUT at a gate)" },
        { value: "company_hr",   label: "Department HR (approves deployments)" },
      ]
    : isVendorAdmin
    ? [{ value: "vendor_operator", label: "Contractor Operator" }]
    : ALL_ROLES;

  const isGateRole = form.role === "company_gate" || (isCompanyAdmin && form.role !== "company_hr");
  const isHrRole   = form.role === "company_hr";

  // Preset + existing departments for consistent naming (server merges
  // config presets with this company's known gate/department names).
  const { data: deptOptions } = useQuery({
    queryKey: ["company-locations", authUser?.company_id],
    queryFn:  () => api.get(`/companies/${authUser.company_id}/locations`).then(r => r.data.locations),
    enabled:  isCompanyAdmin,
  });

  const { data: usersData, isLoading } = useQuery({
    queryKey: ["users"],
    queryFn:  () => api.get("/users").then(r => r.data),
  });
  const users = usersData?.data ?? usersData ?? [];

  const { data: companies = [] } = useQuery({
    queryKey: ["companies-simple"],
    queryFn:  () => api.get("/companies").then(r => r.data?.data ?? r.data),
    enabled:  !isCompanyAdmin && !isVendorAdmin,
  });

  const { data: vendors = [] } = useQuery({
    queryKey: ["vendors-simple"],
    queryFn:  () => api.get("/vendors").then(r => r.data?.data ?? r.data),
    enabled:  !isCompanyAdmin && !isVendorAdmin,
  });

  const openCreate = () => {
    const defaultRole = isCompanyAdmin ? "company_gate" : isVendorAdmin ? "vendor_operator" : "";
    setForm({ ...EMPTY, role: defaultRole });
    setShowPass(false);
    setModal("create");
  };
  const openEdit = (u) => {
    setForm({
      name: u.name, email: u.email, password: "", role: u.role,
      company_id: u.company_id ?? "", vendor_id: u.vendor_id ?? "", phone: u.phone ?? "",
      location_type: u.location_type ?? "", location_name: u.location_name ?? "",
    });
    setShowPass(false);
    setModal(u);
  };
  const close = () => setModal(null);

  const needsCompany = !isCompanyAdmin && !isVendorAdmin && ["company_admin", "company_gate"].includes(form.role);
  const needsVendor  = !isCompanyAdmin && !isVendorAdmin && ["vendor_admin", "vendor_operator"].includes(form.role);

  const createMutation = useMutation({
    mutationFn: (data) => api.post("/users", data),
    onSuccess: () => { toast.success("User created!"); qc.invalidateQueries(["users"]); close(); },
    onError:   (e) => toast.error(e.response?.data?.message || "Create failed."),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }) => api.put(`/users/${id}`, data),
    onSuccess: () => { toast.success("User updated!"); qc.invalidateQueries(["users"]); close(); },
    onError:   (e) => toast.error(e.response?.data?.message || "Update failed."),
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, is_active }) => api.put(`/users/${id}`, { is_active }),
    onSuccess: () => { toast.success("Status updated."); qc.invalidateQueries(["users"]); },
    onError:   () => toast.error("Failed to update status."),
  });

  const deleteMutation = useMutation({
    mutationFn: (id) => api.delete(`/users/${id}`),
    onSuccess: () => { toast.success("User deleted."); qc.invalidateQueries(["users"]); },
    onError:   () => toast.error("Delete failed."),
  });

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = { ...form };
    if (!payload.password)      delete payload.password;
    if (!payload.company_id)    delete payload.company_id;
    if (!payload.vendor_id)     delete payload.vendor_id;
    if (!payload.phone)         delete payload.phone;
    if (!payload.location_type) delete payload.location_type;
    if (!payload.location_name) delete payload.location_name;

    if (modal === "create") {
      createMutation.mutate(payload);
    } else {
      updateMutation.mutate({ id: modal.id, data: payload });
    }
  };

  const field = (k) => ({
    value:    form[k],
    onChange: (e) => setForm(f => ({ ...f, [k]: e.target.value })),
  });

  const locTypePlaceholder = LOCATION_TYPES.find(t => t.value === form.location_type)?.placeholder ?? "Location name";

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isVendorAdmin ? "Operators" : isCompanyAdmin ? "Users" : "User Management"}
          </h1>
          <p className="text-sm text-gray-500 mt-1">
            {isVendorAdmin
              ? "Manage operator accounts for your vendor"
              : isCompanyAdmin
              ? "Manage gate and department users who mark attendance"
              : "Create logins for company admins, gate, department, and vendor staff"}
          </p>
        </div>
        <button onClick={openCreate} className="btn-primary">
          <Plus size={16} /> {isVendorAdmin ? "Add Operator" : "Add User"}
        </button>
      </div>

      <div className="card overflow-hidden p-0">
        {isLoading ? (
          <p className="p-6 text-sm text-gray-400">Loading…</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Name</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Email</th>
                {!isCompanyAdmin && !isVendorAdmin && <th className="text-left px-4 py-3 font-medium text-gray-600">Role</th>}
                {!isCompanyAdmin && !isVendorAdmin && <th className="text-left px-4 py-3 font-medium text-gray-600">Linked To</th>}
                {!isVendorAdmin && <th className="text-left px-4 py-3 font-medium text-gray-600">Location</th>}
                <th className="px-4 py-3 text-left font-medium">Added</th><th className="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {users.map((u) => (
                <tr key={u.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{u.name}</td>
                  <td className="px-4 py-3 text-gray-600">{u.email}</td>
                  {!isCompanyAdmin && !isVendorAdmin && (
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${ROLE_BADGE[u.role] ?? "bg-gray-100 text-gray-600"}`}>
                        {u.role?.replace(/_/g, " ")}
                      </span>
                    </td>
                  )}
                  {!isCompanyAdmin && !isVendorAdmin && (
                    <td className="px-4 py-3 text-gray-500 text-xs">
                      {u.company?.name ?? u.vendor?.name ?? <span className="text-gray-300">—</span>}
                    </td>
                  )}
                  {!isVendorAdmin && (
                    <td className="px-4 py-3">
                      {u.location_name ? (
                        <div className="flex items-center gap-1.5">
                          <MapPin size={12} className="text-brand-500 shrink-0" />
                          <div>
                            <p className="text-gray-800 font-medium leading-none">{u.location_name}</p>
                            <p className="text-gray-400 text-xs mt-0.5">{locationTypeLabel(u.location_type)}</p>
                          </div>
                        </div>
                      ) : (
                        <span className="text-gray-300">—</span>
                      )}
                    </td>
                  )}
                  <td className="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                    {u.created_at?.slice(0, 10) ?? "—"}
                  </td>
                  <td className="px-4 py-3">
                    <button
                      onClick={() => toggleMutation.mutate({ id: u.id, is_active: !u.is_active })}
                      className={`flex items-center gap-1 text-xs font-medium ${u.is_active ? "text-green-600" : "text-gray-400"}`}
                    >
                      {u.is_active
                        ? <><ToggleRight size={18} className="text-green-500" /> Active</>
                        : <><ToggleLeft  size={18} /> Inactive</>}
                    </button>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2 justify-end">
                      <button onClick={() => openEdit(u)} className="p-1 hover:text-brand-600 text-gray-400" title="Edit">
                        <Pencil size={15} />
                      </button>
                      <button
                        onClick={() => { if (confirm(`Delete ${u.name}?`)) deleteMutation.mutate(u.id); }}
                        className="p-1 hover:text-red-500 text-gray-400" title="Delete"
                      >
                        <Trash2 size={15} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {!users.length && (
                <tr>
                  <td colSpan={isVendorAdmin ? 5 : isCompanyAdmin ? 6 : 8} className="px-4 py-8 text-center text-gray-400">
                    {isVendorAdmin
                      ? "No operators yet. Add one to allow attendance scanning."
                      : isCompanyAdmin
                      ? "No users yet. Add one to allow attendance marking."
                      : "No users yet."}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Modal */}
      {modal && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 sticky top-0 bg-white rounded-t-2xl z-10">
              <h2 className="font-semibold text-gray-900">
                {modal === "create"
                  ? (isVendorAdmin ? "Add Operator" : "Add User")
                  : (isVendorAdmin ? "Edit Operator" : "Edit User")}
              </h2>
              <button onClick={close}><X size={18} className="text-gray-400" /></button>
            </div>

            <form onSubmit={handleSubmit} className="p-6 space-y-4">

              {/* ── 1. Role ── */}
              {!isVendorAdmin && (
                <div>
                  <label className="label">Role</label>
                  <select className="input" required {...field("role")}>
                    <option value="">Select role…</option>
                    {availableRoles.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </select>
                </div>
              )}

              {/* ── 2. Company / Vendor ── */}
              {needsCompany && (
                <div>
                  <label className="label">Company</label>
                  <select className="input" required {...field("company_id")}>
                    <option value="">Select company…</option>
                    {companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select>
                </div>
              )}

              {needsVendor && (
                <div>
                  <label className="label">Contractor</label>
                  <select className="input" required {...field("vendor_id")}>
                    <option value="">Select contractor…</option>
                    {vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                  </select>
                </div>
              )}

              {/* ── 3. Login type & location (gate/dept users) ── */}
              {isGateRole && (
                <div className="space-y-3 rounded-xl border border-brand-200 p-4 bg-brand-50">
                  <div className="flex items-center gap-2">
                    <MapPin size={14} className="text-brand-500" />
                    <span className="text-sm font-medium text-gray-700">Login Type & Location</span>
                  </div>

                  <div>
                    <label className="label">Login Type</label>
                    <select className="input" {...field("location_type")}>
                      <option value="">Select type…</option>
                      {LOCATION_TYPES.map(t => (
                        <option key={t.value} value={t.value}>{t.label}</option>
                      ))}
                    </select>
                  </div>

                  {form.location_type && (
                    <div>
                      <label className="label">Location Name</label>
                      <input
                        className="input"
                        list="dept-presets"
                        placeholder={locTypePlaceholder}
                        {...field("location_name")}
                      />
                      <datalist id="dept-presets">
                        {(deptOptions ?? []).map((d) => <option key={d} value={d} />)}
                      </datalist>
                      <p className="text-xs text-gray-400 mt-1">
                        Attendance marked by this user will be tagged with this location. Pick a preset or type a custom name.
                      </p>
                    </div>
                  )}
                </div>
              )}

              {/* ── 3b. HR department ── */}
              {isHrRole && (
                <div>
                  <label className="label">Department (optional)</label>
                  <input
                    className="input"
                    list="dept-presets"
                    placeholder="e.g. HR"
                    {...field("location_name")}
                  />
                  <datalist id="dept-presets">
                    {(deptOptions ?? []).map((d) => <option key={d} value={d} />)}
                  </datalist>
                  <p className="text-xs text-gray-400 mt-1">
                    HR users review contractor deployments: approve/reject and choose which gates/departments each worker may enter.
                  </p>
                </div>
              )}

              {/* ── divider ── */}
              <div className="border-t border-gray-100 pt-1">
                <p className="text-xs text-gray-400 font-medium uppercase tracking-wide mb-3">Account Details</p>

                <div className="space-y-4">
                  <div>
                    <label className="label">Full Name</label>
                    <input className="input" placeholder="e.g. Ramesh Kumar" required {...field("name")} />
                  </div>

                  <div>
                    <label className="label">Email</label>
                    <input className="input" type="email" placeholder="user@company.com" required {...field("email")} />
                  </div>

                  <div>
                    <label className="label">
                      {modal === "create" ? "Password" : "New Password (leave blank to keep)"}
                    </label>
                    <div className="flex gap-2">
                      <div className="relative flex-1">
                        <input
                          className="input pr-10 font-mono"
                          type={showPass ? "text" : "password"}
                          placeholder="Min 8 chars, letters + numbers"
                          required={modal === "create"}
                          {...field("password")}
                        />
                        <button type="button" onClick={() => setShowPass(s => !s)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
                          {showPass ? <EyeOff size={15} /> : <Eye size={15} />}
                        </button>
                      </div>
                      <button type="button" title="Generate"
                        onClick={() => { setForm(f => ({ ...f, password: genPassword() })); setShowPass(true); }}
                        className="btn-secondary px-3">
                        <RefreshCw size={15} />
                      </button>
                      {form.password && (
                        <button type="button" title="Copy"
                          onClick={() => { navigator.clipboard.writeText(form.password); toast.success("Copied!"); }}
                          className="btn-secondary px-3">
                          <Copy size={15} />
                        </button>
                      )}
                    </div>
                    {form.password && <p className="text-xs text-amber-600 mt-1">Save this password — it won't be shown again.</p>}
                  </div>

                  <div>
                    <label className="label">Phone <span className="text-gray-400 font-normal">(optional)</span></label>
                    <input className="input" placeholder="+91 98765 43210" {...field("phone")} />
                  </div>
                </div>
              </div>

              <div className="flex gap-3 pt-2">
                <button type="submit"
                  disabled={createMutation.isPending || updateMutation.isPending}
                  className="btn-primary flex-1 justify-center">
                  {createMutation.isPending || updateMutation.isPending
                    ? "Saving…"
                    : modal === "create" ? "Create User" : "Save Changes"}
                </button>
                <button type="button" onClick={close} className="btn-secondary">Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
