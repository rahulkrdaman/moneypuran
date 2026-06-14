"use client";
import { useState, useEffect } from "react";
import Link from "next/link";
import { useTheme } from "next-themes";
import { Search, Sun, Moon, Menu, X, Bell, TrendingUp } from "lucide-react";
import { Category } from "@/types";

interface HeaderProps { categories: Category[]; breakingNews?: string; }

export function Header({ categories, breakingNews }: HeaderProps) {
  const { theme, setTheme } = useTheme();
  const [mounted, setMounted] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    setMounted(true);
    const onScroll = () => setScrolled(window.scrollY > 60);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const mainNav = categories.filter((c) => !c.parentId && c.isActive).slice(0, 7);

  return (
    <header className={`sticky top-0 z-50 w-full transition-all duration-200 ${scrolled ? "bg-card/95 backdrop-blur shadow-md" : "bg-card"} border-b border-border`}>
      {/* Breaking News Ticker */}
      {breakingNews && (
        <div className="bg-red-600 text-white text-xs py-1.5 overflow-hidden">
          <div className="container flex items-center gap-3">
            <span className="flex-shrink-0 font-bold bg-white text-red-600 px-2 py-0.5 rounded text-xs">BREAKING</span>
            <div className="overflow-hidden flex-1">
              <div className="ticker-text whitespace-nowrap">{breakingNews}</div>
            </div>
          </div>
        </div>
      )}

      {/* Top Bar */}
      <div className="border-b border-border/50">
        <div className="container flex h-10 items-center justify-between text-xs text-muted-foreground">
          <div className="flex items-center gap-4">
            <span>{new Date().toLocaleDateString("en-IN", { weekday:"long", year:"numeric", month:"long", day:"numeric" })}</span>
            <Link href="/markets" className="flex items-center gap-1 hover:text-brand-600 transition-colors">
              <TrendingUp className="h-3 w-3" /><span>Markets</span>
            </Link>
          </div>
          <div className="flex items-center gap-4">
            <Link href="/newsletter" className="hover:text-brand-600 transition-colors flex items-center gap-1"><Bell className="h-3 w-3" />Newsletter</Link>
            <Link href="/admin" className="hover:text-brand-600 transition-colors">Admin</Link>
          </div>
        </div>
      </div>

      {/* Logo & Search */}
      <div className="container flex h-16 items-center justify-between">
        <Link href="/" className="flex items-center gap-2">
          <div className="flex items-center justify-center h-9 w-9 rounded-lg bg-brand-600 text-white font-bold text-lg">₹</div>
          <div>
            <div className="text-xl font-heading font-bold text-foreground leading-tight">MoneyPuran</div>
            <div className="text-xs text-muted-foreground">Finance & Business News</div>
          </div>
        </Link>

        {/* Search */}
        <div className="hidden md:flex flex-1 max-w-md mx-8">
          <form action="/search" className="relative w-full">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <input name="q" placeholder="Search news, stocks, companies..." className="input pl-10 h-9" />
          </form>
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2">
          <button onClick={() => setSearchOpen(true)} className="md:hidden btn-secondary h-9 w-9 p-0">
            <Search className="h-4 w-4" />
          </button>
          {mounted && (
            <button onClick={() => setTheme(theme === "dark" ? "light" : "dark")} className="btn-secondary h-9 w-9 p-0">
              {theme === "dark" ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            </button>
          )}
          <button onClick={() => setMobileOpen(!mobileOpen)} className="md:hidden btn-secondary h-9 w-9 p-0">
            {mobileOpen ? <X className="h-4 w-4" /> : <Menu className="h-4 w-4" />}
          </button>
        </div>
      </div>

      {/* Navigation */}
      <nav className="hidden md:block border-t border-border/50">
        <div className="container flex items-center gap-0">
          {mainNav.map((cat) => (
            <Link key={cat.id} href={`/category/${cat.slug}`}
              className="px-4 py-3 text-sm font-medium text-muted-foreground hover:text-brand-600 hover:bg-muted/50 transition-all relative group">
              {cat.name}
              <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-brand-600 scale-x-0 group-hover:scale-x-100 transition-transform origin-left" />
            </Link>
          ))}
          <Link href="/markets" className="px-4 py-3 text-sm font-medium text-finance-green hover:text-finance-green/80 transition-colors ml-auto">
            📈 Markets Live
          </Link>
        </div>
      </nav>

      {/* Mobile Menu */}
      {mobileOpen && (
        <div className="md:hidden border-t border-border bg-card">
          <form action="/search" className="p-4">
            <input name="q" placeholder="Search..." className="input" />
          </form>
          <div className="px-4 pb-4 space-y-1">
            {mainNav.map((cat) => (
              <Link key={cat.id} href={`/category/${cat.slug}`}
                onClick={() => setMobileOpen(false)}
                className="block px-3 py-2 rounded-lg text-sm font-medium hover:bg-muted transition-colors">
                {cat.name}
              </Link>
            ))}
          </div>
        </div>
      )}

      {/* Mobile Search Overlay */}
      {searchOpen && (
        <div className="fixed inset-0 z-50 bg-background/95 backdrop-blur p-4 md:hidden">
          <div className="flex items-center gap-3">
            <form action="/search" className="flex-1">
              <input name="q" autoFocus placeholder="Search news..." className="input text-lg h-12" value={searchQuery} onChange={e => setSearchQuery(e.target.value)} />
            </form>
            <button onClick={() => setSearchOpen(false)} className="btn-secondary h-12 px-4">Cancel</button>
          </div>
        </div>
      )}
    </header>
  );
}
