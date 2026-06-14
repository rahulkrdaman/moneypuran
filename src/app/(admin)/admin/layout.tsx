"use client";
import { useState, useEffect } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { LayoutDashboard, FileText, FolderOpen, Tag, Users, Megaphone, Bot, BarChart3, Settings, Search, LogOut, Menu, X, Bell, ChevronDown, Rss, Globe } from "lucide-react";

const navItems = [
  { href: "/admin", icon: LayoutDashboard, label: "Dashboard", exact: true },
  { href: "/admin/posts", icon: FileText, label: "Posts" },
  { href: "/admin/categories", icon: FolderOpen, label: "Categories" },
  { href: "/admin/tags", icon: Tag, label: "Tags" },
  { href: "/admin/users", icon: Users, label: "Users" },
  { href: "/admin/ads", icon: Megaphone, label: "Advertisements" },
  { href: "/admin/rss-sources", icon: Rss, label: "RSS Sources" },
  { href: "/admin/ai-agent", icon: Bot, label: "AI Agent" },
  { href: "/admin/analytics", icon: BarChart3, label: "Analytics" },
  { href: "/admin/seo", icon: Globe, label: "SEO" },
  { href: "/admin/settings", icon: Settings, label: "Settings" },
];

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [user, setUser] = useState<{ name: string; role: string } | null>(null);
  const pathname = usePathname();
  const router = useRouter();

  useEffect(() => {
    fetch("/api/auth/me").then(r => r.json()).then(d => {
      if (d.success) setUser({ name: `${d.data.firstName} ${d.data.lastName}`, role: d.data.role });
      else router.push("/admin/login");
    }).catch(() => router.push("/admin/login"));
  }, [router]);

  async function handleLogout() {
    await fetch("/api/auth/logout", { method: "POST" });
    router.push("/admin/login");
  }

  const isActive = (href: string, exact = false) => exact ? pathname === href : pathname.startsWith(href);

  return (
    <div className="flex h-screen bg-muted/30 overflow-hidden">
      {/* Sidebar */}
      <aside className={`${sidebarOpen ? "w-64" : "w-16"} transition-all duration-300 bg-card border-r border-border flex flex-col flex-shrink-0`}>
        {/* Logo */}
        <div className="flex items-center gap-3 p-4 border-b border-border h-16">
          <div className="h-8 w-8 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold flex-shrink-0">₹</div>
          {sidebarOpen && <div><p className="font-bold text-sm leading-tight">MoneyPuran</p><p className="text-xs text-muted-foreground">Admin Panel</p></div>}
        </div>

        {/* Nav */}
        <nav className="flex-1 overflow-y-auto p-2 space-y-0.5">
          {navItems.map(({ href, icon: Icon, label, exact }) => (
            <Link key={href} href={href}
              className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all group
                ${isActive(href, exact) ? "bg-brand-600 text-white" : "text-muted-foreground hover:bg-muted hover:text-foreground"}`}>
              <Icon className="h-4.5 w-4.5 flex-shrink-0" />
              {sidebarOpen && <span>{label}</span>}
              {!sidebarOpen && (
                <div className="absolute left-16 bg-popover text-foreground text-xs px-2 py-1 rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-50 whitespace-nowrap">
                  {label}
                </div>
              )}
            </Link>
          ))}
        </nav>

        {/* User */}
        {user && (
          <div className="p-3 border-t border-border">
            {sidebarOpen ? (
              <div className="flex items-center gap-2">
                <div className="h-8 w-8 rounded-full bg-brand-100 dark:bg-brand-950 flex items-center justify-center text-brand-600 font-bold text-sm flex-shrink-0">
                  {user.name[0]}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{user.name}</p>
                  <p className="text-xs text-muted-foreground">{user.role.replace("_"," ")}</p>
                </div>
                <button onClick={handleLogout} className="p-1 rounded hover:bg-muted text-muted-foreground hover:text-red-500 transition-colors" title="Logout">
                  <LogOut className="h-4 w-4" />
                </button>
              </div>
            ) : (
              <button onClick={handleLogout} className="w-full flex justify-center p-1 rounded hover:bg-muted text-muted-foreground hover:text-red-500 transition-colors" title="Logout">
                <LogOut className="h-4 w-4" />
              </button>
            )}
          </div>
        )}
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Topbar */}
        <header className="h-16 bg-card border-b border-border flex items-center justify-between px-4 flex-shrink-0">
          <div className="flex items-center gap-3">
            <button onClick={() => setSidebarOpen(!sidebarOpen)} className="btn-secondary h-8 w-8 p-0">
              <Menu className="h-4 w-4" />
            </button>
            <div className="relative hidden md:block">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
              <input placeholder="Search posts, users..." className="input pl-9 h-8 text-sm w-64" />
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button className="btn-secondary h-8 w-8 p-0 relative">
              <Bell className="h-4 w-4" />
              <span className="absolute top-1 right-1 h-2 w-2 bg-red-500 rounded-full" />
            </button>
            <Link href="/" target="_blank" className="btn-secondary text-xs h-8 px-3">View Site</Link>
          </div>
        </header>

        {/* Content */}
        <main className="flex-1 overflow-y-auto p-6">{children}</main>
      </div>
    </div>
  );
}
