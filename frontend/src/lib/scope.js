import { useAuth } from "@/contexts/AuthContext";

/**
 * Who is looking, and what does that make redundant on screen.
 *
 * The rule this exists for: a company user is ALWAYS inside their own
 * company, so printing that company's name on every row of every table
 * tells them nothing. Vendors and super admins work across companies, so
 * they keep the column — until they narrow to one, which says it already.
 *
 * Every page that can show a company name should ask this helper instead of
 * re-deriving the roles, so the rule stays the same everywhere.
 */
export function useOrgScope() {
  const { user } = useAuth();
  const role = user?.role;

  const isCompanyUser = ["company_admin", "company_hr", "company_gate"].includes(role);
  const isVendorUser  = ["vendor_admin", "vendor_operator"].includes(role);
  const isSuperAdmin  = role === "super_admin";

  return {
    user,
    role,
    isCompanyUser,
    isVendorUser,
    isSuperAdmin,
    /** Own company name — for headings, where saying it ONCE is useful. */
    companyName: user?.company?.name ?? null,
    /**
     * Show a Company column/label?
     * @param {string|number} [pickedCompanyId] a single-company filter, if any
     */
    showCompany: (pickedCompanyId) => !isCompanyUser && !pickedCompanyId,
  };
}
