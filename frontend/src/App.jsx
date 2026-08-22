import { Routes, Route, Navigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import MainLayout from "@/components/layout/MainLayout";
import Login from "@/pages/Login";
import Dashboard from "@/pages/Dashboard";
import CompanyList from "@/pages/companies/CompanyList";
import VendorList from "@/pages/vendors/VendorList";
import VendorProfile from "@/pages/vendors/VendorProfile";
import VendorApproval from "@/pages/vendors/VendorApproval";
import VendorCompanyAccess from "@/pages/vendors/VendorCompanyAccess";
import VendorDetail from "@/pages/vendors/VendorDetail";
import WorkerList from "@/pages/workers/WorkerList";
import WorkerDetail from "@/pages/workers/WorkerDetail";
import WorkerRegister from "@/pages/workers/WorkerRegister";
import WorkerAssign from "@/pages/workers/WorkerAssign";
import AttendanceMark from "@/pages/attendance/AttendanceMark";
import AttendanceList from "@/pages/attendance/AttendanceList";
import LiveBoard from "@/pages/attendance/LiveBoard";
import Visitors from "@/pages/visitors/Visitors";
import AttendanceExceptions from "@/pages/attendance/AttendanceExceptions";
import UserList from "@/pages/users/UserList";
import Downloads from "@/pages/Downloads";
import Signup from "@/pages/Signup";
import ForgotPassword from "@/pages/ForgotPassword";
import ResetPassword from "@/pages/ResetPassword";
import PlanBilling from "@/pages/PlanBilling";
import Templates from "@/pages/Templates";
import ReleaseNotes from "@/pages/ReleaseNotes";
import Subscriptions from "@/pages/Subscriptions";

function PrivateRoute({ children, roles }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="min-h-screen flex items-center justify-center">Loading...</div>;
  if (!user) return <Navigate to="/login" replace />;
  if (roles && !roles.includes(user.role)) return <Navigate to="/dashboard" replace />;
  return children;
}

export default function App() {
  const { user } = useAuth();

  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/dashboard" /> : <Login />} />
      <Route path="/signup" element={user ? <Navigate to="/dashboard" /> : <Signup />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />

      <Route path="/" element={<PrivateRoute><MainLayout /></PrivateRoute>}>
        <Route index element={<Navigate to="/dashboard" />} />
        <Route path="dashboard" element={<Dashboard />} />
        <Route path="downloads" element={<Downloads />} />
        <Route path="whats-new" element={<ReleaseNotes />} />
        <Route path="billing" element={
          <PrivateRoute roles={["company_admin", "vendor_admin"]}><PlanBilling /></PrivateRoute>
        } />
        <Route path="settings/templates" element={
          <PrivateRoute roles={["super_admin", "company_admin", "vendor_admin"]}><Templates /></PrivateRoute>
        } />
        <Route path="subscriptions" element={
          <PrivateRoute roles={["super_admin"]}><Subscriptions /></PrivateRoute>
        } />

        {/* Companies — super admin only */}
        <Route path="companies" element={
          <PrivateRoute roles={["super_admin"]}>
            <CompanyList />
          </PrivateRoute>
        } />

        {/* Users — super_admin (all), company_admin (gate users), vendor_admin (operators) */}
        <Route path="users" element={
          <PrivateRoute roles={["super_admin", "company_admin", "vendor_admin"]}>
            <UserList />
          </PrivateRoute>
        } />

        {/* Vendors */}
        <Route path="vendors" element={
          <PrivateRoute roles={["super_admin", "company_admin", "company_gate"]}>
            <VendorList />
          </PrivateRoute>
        } />
        <Route path="profile" element={
          <PrivateRoute roles={["vendor_admin"]}>
            <VendorProfile />
          </PrivateRoute>
        } />
        <Route path="vendors/approval" element={
          <PrivateRoute roles={["super_admin", "company_admin"]}>
            <VendorApproval />
          </PrivateRoute>
        } />
        <Route path="vendors/company-access" element={
          <PrivateRoute roles={["vendor_admin"]}>
            <VendorCompanyAccess />
          </PrivateRoute>
        } />
        <Route path="vendors/:id" element={
          <PrivateRoute roles={["company_admin", "company_hr", "company_gate"]}>
            <VendorDetail />
          </PrivateRoute>
        } />

        {/* Workers */}
        <Route path="workers" element={<WorkerList />} />
        <Route path="workers/:id" element={<WorkerDetail />} />
        <Route path="workers/register" element={
          <PrivateRoute roles={["super_admin", "vendor_admin", "vendor_operator"]}>
            <WorkerRegister />
          </PrivateRoute>
        } />
        <Route path="workers/:id/edit" element={
          <PrivateRoute roles={["super_admin", "vendor_admin", "vendor_operator"]}>
            <WorkerRegister />
          </PrivateRoute>
        } />
        <Route path="workers/assign" element={
          <PrivateRoute roles={["super_admin", "vendor_admin", "company_admin", "company_hr"]}>
            <WorkerAssign />
          </PrivateRoute>
        } />

        {/* Attendance */}
        <Route path="attendance" element={<AttendanceList />} />
        <Route path="live" element={<LiveBoard />} />
        <Route path="visitors" element={
          <PrivateRoute roles={["super_admin", "company_admin", "company_hr", "company_gate"]}>
            <Visitors />
          </PrivateRoute>
        } />
        <Route path="attendance/mark" element={
          <PrivateRoute roles={["super_admin", "company_admin", "company_gate"]}>
            <AttendanceMark />
          </PrivateRoute>
        } />
        <Route path="attendance/exceptions" element={<AttendanceExceptions />} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" />} />
    </Routes>
  );
}
