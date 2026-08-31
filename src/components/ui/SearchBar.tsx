"use client";
import { useState, useEffect, useRef, useCallback } from "react";
import { Search, X, Clock, TrendingUp, ArrowRight } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";

interface SearchResult { id: string; title: string; slug: string; excerpt: string | null; category: { name: string; slug: string } | null; publishedAt: string | null }

function debounce<A extends unknown[]>(fn: (...args: A) => unknown, ms: number) {
  let timer: ReturnType<typeof setTimeout>;
  return (...args: A) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
}

interface SearchBarProps { placeholder?: string; className?: string; size?: "sm" | "md" | "lg" }

export default function SearchBar({ placeholder = "Search news, stocks, companies…", className = "", size = "md" }: SearchBarProps) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const [history, setHistory] = useState<string[]>([]);
  const ref = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const router = useRouter();

  useEffect(() => {
    const saved = localStorage.getItem("mp_search_history");
    if (saved) setHistory(JSON.parse(saved).slice(0, 5));
  }, []);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  const doSearch = useCallback(debounce(async (q: string) => {
    if (q.length < 2) { setResults([]); setLoading(false); return; }
    const res = await fetch(`/api/posts?search=${encodeURIComponent(q)}&limit=6&status=PUBLISHED`);
    const d = await res.json();
    setResults(d.data || []);
    setLoading(false);
  }, 350), []);

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const q = e.target.value;
    setQuery(q);
    if (q.length >= 2) { setLoading(true); setOpen(true); doSearch(q); }
    else { setResults([]); setOpen(true); setLoading(false); }
  }

  function saveHistory(q: string) {
    const updated = [q, ...history.filter(h => h !== q)].slice(0, 5);
    setHistory(updated);
    localStorage.setItem("mp_search_history", JSON.stringify(updated));
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!query.trim()) return;
    saveHistory(query.trim());
    setOpen(false);
    router.push(`/search?q=${encodeURIComponent(query.trim())}`);
  }

  function handleResultClick(q: string) {
    saveHistory(q); setOpen(false); setQuery("");
  }

  const sizeClasses = { sm: "h-9 text-sm", md: "h-10 text-sm", lg: "h-12 text-base" };
  const iconSize = size === "lg" ? "h-5 w-5" : "h-4 w-4";

  return (
    <div ref={ref} className={`relative ${className}`}>
      <form onSubmit={handleSubmit}>
        <div className="relative">
          <Search className={`absolute left-3 top-1/2 -translate-y-1/2 ${iconSize} text-muted-foreground`} />
          <input
            ref={inputRef}
            value={query}
            onChange={handleChange}
            onFocus={() => setOpen(true)}
            className={`input pl-9 pr-9 ${sizeClasses[size]} w-full`}
            placeholder={placeholder}
            autoComplete="off"
          />
          {query && (
            <button type="button" onClick={() => { setQuery(""); setResults([]); inputRef.current?.focus(); }}
              className="absolute right-3 top-1/2 -translate-y-1/2 p-0.5 rounded hover:bg-muted text-muted-foreground">
              <X className={iconSize} />
            </button>
          )}
        </div>
      </form>

      {/* Dropdown */}
      {open && (
        <div className="absolute top-full left-0 right-0 mt-1 bg-card border border-border rounded-xl shadow-xl z-50 overflow-hidden">
          {/* Search history (when no query) */}
          {!query && history.length > 0 && (
            <div className="p-2">
              <p className="px-2 py-1 text-xs font-medium text-muted-foreground flex items-center gap-1.5"><Clock className="h-3 w-3" /> Recent</p>
              {history.map(h => (
                <button key={h} onClick={() => { setQuery(h); doSearch(h); setLoading(true); }}
                  className="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm hover:bg-muted text-left">
                  <Clock className="h-3.5 w-3.5 text-muted-foreground" /> <span>{h}</span>
                </button>
              ))}
            </div>
          )}

          {/* Loading */}
          {loading && (
            <div className="p-4 text-center text-sm text-muted-foreground">
              <div className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-brand-600 border-t-transparent mr-2" />
              Searching…
            </div>
          )}

          {/* Results */}
          {!loading && results.length > 0 && (
            <div className="p-2">
              {results.map(r => (
                <Link key={r.id} href={`/article/${r.slug}`} onClick={() => handleResultClick(r.title)}
                  className="flex gap-3 p-2 rounded-lg hover:bg-muted transition-colors">
                  <Search className="h-4 w-4 text-muted-foreground flex-shrink-0 mt-0.5" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium truncate leading-tight">{r.title}</p>
                    {r.category && <p className="text-xs text-muted-foreground mt-0.5">{r.category.name}</p>}
                  </div>
                </Link>
              ))}
              <Link href={`/search?q=${encodeURIComponent(query)}`} onClick={() => setOpen(false)}
                className="flex items-center justify-between px-3 py-2 mt-1 rounded-lg bg-muted/50 hover:bg-muted text-sm font-medium text-brand-600 transition-colors">
                See all results for &quot;{query}&quot; <ArrowRight className="h-4 w-4" />
              </Link>
            </div>
          )}

          {/* No results */}
          {!loading && query.length >= 2 && results.length === 0 && (
            <div className="p-6 text-center">
              <TrendingUp className="h-6 w-6 mx-auto text-muted-foreground mb-2 opacity-50" />
              <p className="text-sm text-muted-foreground">No results for &quot;{query}&quot;</p>
              <Link href={`/search?q=${encodeURIComponent(query)}`} onClick={() => setOpen(false)}
                className="text-xs text-brand-600 hover:underline mt-1 inline-block">Try full search</Link>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
