import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import { Building2, Pencil, X, Check } from "lucide-react";

const FIELDS = [
  { key: "name",           label: "Contractor Name",     span: 2 },
  { key: "contact_person", label: "Contact Person",  span: 1 },
  { key: "contact_email",  label: "Contact Email",   span: 1 },
  { key: "contact_phone",  label: "Contact Phone",   span: 1 },
  { key: "address",        label: "Address",         span: 2 },
  { key: "city",           label: "City",            span: 1 },
  { key: "state",          label: "State",           span: 1 },
  { key: "pin",            label: "PIN Code",        span: 1 },
];

export default function VendorProfile() {
  const { user }      = useAuth();
  const queryClient   = useQueryClient();
  const canEdit       = user?.role === "vendor_admin";
  const [editing, setEditing] = useState(false);
  const [form, setForm]       = useState({});

  const { data: vendor, isLoading } = useQuery({
    queryKey: ["vendor-profile", user?.vendor_id],
    queryFn:  () => api.get(`/vendors/${user.vendor_id}`).then(r => r.data),
    enabled:  !!user?.vendor_id,
  });

  useEffect(() => {
    if (vendor) {
      setForm(Object.fromEntries(FIELDS.map(f => [f.key, vendor[f.key] ?? ""])));
    }
  }, [vendor]);

  const updateMutation = useMutation({
    mutationFn: (data) => api.put(`/vendors/${vendor.id}`, data),
    onSuccess: () => {
      toast.success("Profile updated.");
      queryClient.invalidateQueries(["vendor-profile", user?.vendor_id]);
      queryClient.invalidateQueries(["vendors"]);
      setEditing(false);
    },
    onError: (e) => toast.error(
      Object.values(e.response?.data?.errors ?? {})[0]?.[0] ?? "Update failed."
    ),
  });

  const handleCancel = () => {
    setForm(Object.fromEntries(FIELDS.map(f => [f.key, vendor?.[f.key] ?? ""])));
    setEditing(false);
  };

  if (isLoading) {
    return (
      <div className="max-w-2xl space-y-4">
        {[...Array(4)].map((_, i) => (
          <div key={i} className="h-10 bg-gray-100 rounded-lg animate-pulse" />
        ))}
      </div>
    );
  }

  if (!vendor) {
    return <p className="text-gray-500">Organization not found.</p>;
  }

  return (
    <div className="max-w-2xl space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">My Organization</h1>
        <p className="text-sm text-gray-500 mt-0.5">
          {canEdit ? "View and edit your organization's details." : "Your organization's details."}
        </p>
      </div>

      {/* Identity card */}
      <div className="card flex items-center gap-4">
        <div className="w-14 h-14 rounded-2xl bg-brand-50 flex items-center justify-center shrink-0">
          <Building2 size={26} className="text-brand-600" />
        </div>
        <div className="min-w-0">
          <h2 className="text-lg font-bold text-gray-900 truncate">{vendor.name}</h2>
          <p className="text-xs text-gray-400 font-mono">{vendor.code}</p>
        </div>
        <div className="ml-auto shrink-0">
          <span className={`badge ${vendor.status === "active" ? "badge-green" : "badge-gray"}`}>
            {vendor.status}
          </span>
        </div>
      </div>

      {/* Details card */}
      <div className="card space-y-5">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-gray-900">Contact &amp; Address</h3>
          {canEdit && !editing && (
            <button
              onClick={() => setEditing(true)}
              className="btn-secondary py-1.5 text-xs"
            >
              <Pencil size={13} /> Edit
            </button>
          )}
          {editing && (
            <div className="flex gap-2">
              <button
                onClick={() => updateMutation.mutate(form)}
                disabled={updateMutation.isPending}
                className="btn-primary py-1.5 text-xs"
              >
                <Check size={13} />
                {updateMutation.isPending ? "Saving…" : "Save"}
              </button>
              <button onClick={handleCancel} className="btn-secondary py-1.5 text-xs">
                <X size={13} /> Cancel
              </button>
            </div>
          )}
        </div>

        <div className="grid grid-cols-2 gap-4">
          {FIELDS.map(({ key, label, span }) => (
            <div key={key} className={span === 2 ? "col-span-2" : "col-span-1"}>
              <label className="label">{label}</label>
              {editing ? (
                <input
                  className="input"
                  value={form[key] ?? ""}
                  onChange={(e) => setForm(p => ({ ...p, [key]: e.target.value }))}
                />
              ) : (
                <p className="text-sm text-gray-800 py-2 min-h-[2.25rem]">
                  {vendor[key] || <span className="text-gray-400 italic">Not provided</span>}
                </p>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
