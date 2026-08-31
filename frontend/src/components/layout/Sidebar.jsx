import PropTypes from "prop-types";
import { NavLink } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import {
  LayoutDashboard, Building2, UserCog, Users, Settings, ShieldCheck,
  UserCheck, ClipboardList, BarChart2, AlertTriangle, Activity, Contact,
  Download as DownloadIcon, CreditCard, Fingerprint, X, Sparkles, FileSpreadsheet, IndianRupee } from "lucide-react";

const NAV = [
  {
    // What people open the app to do, in the order they do it.
    label: "Daily",
    items: [
      { to: "/dashboard",       icon: LayoutDashboard, label: "Dashboard", roles: ["all"] },
      { to: "/live",            icon: Activity, label: "Live Board", roles: ["all"] },
    ],
  },
  {
    label: "People",
    items: [
      { to: "/workers",          icon: UserCheck,     label: "Workers", roles: ["all"], end: true },
      { to: "/workers/register", icon: Users,         label: "Register Worker",
        roles: ["super_admin", "vendor_admin", "vendor_operator"] },
      { to: "/workers/assign",   icon: ClipboardList, label: "Deploy Workers",
        roles: ["super_admin", "vendor_admin"] },
      { to: "/workers/assign",   icon: ShieldCheck,   label: "Deployment Approvals",
        roles: ["company_admin", "company_hr"] },
      { to: "/visitors",         icon: Contact,       label: "Visitors",
        roles: ["super_admin", "company_admin", "company_hr", "company_gate"] },
    ],
  },
  {
    label: "Attendance",
    items: [
      { to: "/attendance",            icon: BarChart2,       label: "Attendance Log", roles: ["all"], end: true },
      { to: "/attendance/exceptions", icon: AlertTriangle,   label: "Still Inside",
        roles: ["super_admin", "company_admin", "company_hr", "company_gate", "vendor_admin", "vendor_operator"] },
      { to: "/reports",               icon: FileSpreadsheet, label: "Reports", roles: ["all"] },
    ],
  },
  {
    // Money is a different job from presence — it gets its own heading.
    label: "Payroll",
    items: [
      { to: "/payroll", icon: IndianRupee, label: "Payroll & Wages",
        roles: ["super_admin", "company_admin", "company_hr", "vendor_admin"] },
    ],
  },
  {
    label: "Organisation",
    items: [
      { to: "/companies", icon: Building2, label: "Companies", roles: ["super_admin"] },
      // One contractor entry, not two: the approvals page carries the same
      // status tabs plus the approve/reject actions, so it is the superset.
      { to: "/vendors",          icon: Users,       label: "Contractors",
        roles: ["super_admin", "company_admin"], end: true },
      { to: "/users",            icon: UserCog,     label: "Users",
        roles: ["super_admin", "company_admin"] },
      { to: "/users",            icon: UserCog,     label: "Operators", roles: ["vendor_admin"] },
      { to: "/profile",          icon: Settings,    label: "My Organization", roles: ["vendor_admin"] },
      { to: "/vendors/company-access", icon: Building2, label: "Company Access", roles: ["vendor_admin"] },
    ],
  },
  {
    label: "Account & Help",
    items: [
      { to: "/billing",            icon: CreditCard,   label: "Plan & Billing",
        roles: ["company_admin", "vendor_admin"] },
      { to: "/subscriptions",      icon: CreditCard,   label: "Subscriptions", roles: ["super_admin"] },
      { to: "/settings/templates", icon: Settings,     label: "Message Templates",
        roles: ["super_admin", "company_admin", "vendor_admin"] },
      { to: "/downloads",          icon: DownloadIcon, label: "Apps & Guides", roles: ["all"] },
      { to: "/whats-new",          icon: Sparkles,     label: "What's New", roles: ["all"] },
    ],
  },
];

export default function Sidebar({ open, onClose }) {
  const { user } = useAuth();

  const canSee = (roles) => roles.includes("all") || roles.includes(user?.role);

  if (!open) return null;

  return (
    <>
      {/* Mobile overlay — button so keyboard users can dismiss */}
      <button
        type="button"
        aria-label="Close sidebar"
        className="fixed inset-0 bg-black/30 z-20 lg:hidden w-full cursor-default"
        onClick={onClose}
        onKeyDown={(e) => e.key === "Escape" && onClose()}
      />

      <aside className="fixed left-0 top-0 bottom-0 w-64 bg-white border-r border-gray-200 z-30 flex flex-col">
        {/* Logo */}
        <div className="h-16 flex items-center justify-between px-5 border-b border-gray-100">
          <div className="flex items-center gap-2">
            <Fingerprint className="w-7 h-7 text-brand-600" />
            <span className="text-lg font-bold text-gray-900">TrueCrew</span>
          </div>
          <button type="button" className="p-1 rounded lg:hidden" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {/* Role badge */}
        <div className="px-4 py-2 bg-brand-50 border-b border-brand-100">
          <p className="text-xs text-brand-700 font-medium truncate">{user?.name}</p>
          <p className="text-xs text-brand-500 capitalize">{user?.role?.replace("_", " ")}</p>
          {user?.company && <p className="text-xs text-gray-500 truncate">{user.company.name}</p>}
          {user?.vendor  && <p className="text-xs text-gray-500 truncate">{user.vendor.name}</p>}
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto py-4 px-3 space-y-5">
          {NAV.map((section) => {
            const visibleItems = section.items.filter((i) => canSee(i.roles));
            if (!visibleItems.length) return null;

            return (
              <div key={section.label}>
                <p className="px-3 mb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">
                  {section.label}
                </p>
                <div className="space-y-0.5">
                  {visibleItems.map((item) => (
                    <NavLink
                      key={`${item.to}-${item.label}`}
                      to={item.to}
                      end={item.end ?? false}
                      className={({ isActive }) =>
                        `flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                          isActive
                            ? "bg-brand-50 text-brand-700"
                            : "text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                        }`
                      }
                    >
                      <item.icon size={17} />
                      {item.label}
                    </NavLink>
                  ))}
                </div>
              </div>
            );
          })}
        </nav>
      </aside>
    </>
  );
}

Sidebar.propTypes = {
  open:    PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
};
