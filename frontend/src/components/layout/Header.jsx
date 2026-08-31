import { useAuth } from "@/contexts/AuthContext";
import { useNavigate } from "react-router-dom";
import { Menu, LogOut } from "lucide-react";
import toast from "react-hot-toast";
import NotificationBell from "@/components/NotificationBell";

export default function Header({ onToggleSidebar }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    toast.success("Logged out.");
    navigate("/login");
  };

  return (
    <header className="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 sticky top-0 z-10">
      <button
        onClick={onToggleSidebar}
        className="p-2 rounded-lg hover:bg-gray-100 text-gray-500"
        title="Toggle sidebar"
      >
        <Menu size={20} />
      </button>

      <div className="flex items-center gap-3">
        <NotificationBell />
        <div className="h-6 w-px bg-gray-200" />
        <span className="text-sm text-gray-600 hidden sm:block">{user?.name}</span>
        <button
          onClick={handleLogout}
          className="p-2 rounded-lg hover:bg-red-50 text-gray-500 hover:text-red-600 transition-colors"
          title="Logout"
        >
          <LogOut size={18} />
        </button>
      </div>
    </header>
  );
}
