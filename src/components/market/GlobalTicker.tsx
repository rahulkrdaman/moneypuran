"use client";

/**
 * Global market ticker (spec §4).
 *   Desktop  → continuous horizontal marquee, pauses on hover.
 *   Mobile   → free horizontal swipe (native scroll), marquee off.
 *   Colour   → green/red used ONLY for the change value.
 *   Freshness→ every payload carries `_freshness`; a "Demo data" pill shows when
 *              the backend is in mock mode so a viewer is never misled.
 */
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { cn } from "@/lib/utils";

type TickerItem = {
  symbol: string;
  name: string;
  assetClass: string;
  price: number;
  currency: string;
  changePct: number | null;
  entitlement: "realtime" | "delayed" | "eod" | "mock";
  _dir: "up" | "down" | "flat";
  _freshness: string;
};

const REFRESH_MS = 20_000;

function slugFor(it: TickerItem): string | null {
  const c = it.assetClass;
  if (c === "index") return `/markets/${indexSlug(it.symbol)}`;
  if (c === "crypto") return `/crypto/${it.symbol.toLowerCase()}`;
  if (c === "forex") return `/forex/${it.symbol.slice(0, 3).toLowerCase()}-${it.symbol.slice(3).toLowerCase()}`;
  if (c === "commodity") return `/commodities/${commoditySlug(it.symbol)}`;
  if (c === "stock" || c === "etf") return `/stocks/${it.symbol.toLowerCase()}`;
  return null;
}
const indexSlug = (s: string) =>
  ({ "^GSPC": "sp-500", "^IXIC": "nasdaq", "^DJI": "dow-jones", "^FTSE": "ftse-100", "^GDAXI": "dax", "^FCHI": "cac-40", "^N225": "nikkei-225", "^HSI": "hang-seng", "^SSEC": "shanghai-composite", "^NSEI": "nifty-50", "^BSESN": "sensex" }[s] ?? s.replace("^", "").toLowerCase());
const commoditySlug = (s: string) =>
  ({ XAU: "gold", XAG: "silver", WTI: "wti-crude", BRENT: "brent-crude", NG: "natural-gas", HG: "copper" }[s] ?? s.toLowerCase());

function fmt(n: number): string {
  if (!Number.isFinite(n)) return "—";
  const a = Math.abs(n);
  const dp = a >= 1000 ? 0 : a >= 1 ? 2 : 4;
  return n.toLocaleString("en-US", { minimumFractionDigits: dp, maximumFractionDigits: dp });
}

export function GlobalTicker() {
  const [items, setItems] = useState<TickerItem[] | null>(null);
  const [mock, setMock] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    let alive = true;
    const load = async () => {
      try {
        const res = await fetch("/api/market/ticker", { cache: "no-store" });
        const json = await res.json();
        if (!alive || !json?.ok) return;
        const list: TickerItem[] = json.data.items;
        setItems(list);
        setMock(list.some((i) => i.entitlement === "mock"));
      } catch {
        /* keep last-known */
      } finally {
        if (alive) timer.current = setTimeout(load, REFRESH_MS);
      }
    };
    load();
    return () => {
      alive = false;
      if (timer.current) clearTimeout(timer.current);
    };
  }, []);

  if (!items) return <TickerSkeleton />;

  const row = items.map((it) => <TickerCell key={it.symbol} item={it} />);

  return (
    <div className="relative border-y border-border bg-card/60 backdrop-blur supports-[backdrop-filter]:bg-card/40">
      {mock && (
        <span className="absolute right-2 top-1/2 z-10 -translate-y-1/2 rounded bg-brand-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-400">
          Demo data
        </span>
      )}
      {/* Mobile: swipeable */}
      <div className="flex gap-1 overflow-x-auto px-2 py-1.5 md:hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {row}
      </div>
      {/* Desktop: marquee */}
      <div className="group hidden overflow-hidden md:block">
        <div className="flex w-max animate-[ticker_var(--ticker-dur,60s)_linear_infinite] gap-1 py-1.5 group-hover:[animation-play-state:paused]">
          {row}
          {row /* duplicate for seamless loop */}
        </div>
      </div>
    </div>
  );
}

function TickerCell({ item }: { item: TickerItem }) {
  const href = slugFor(item);
  const color =
    item._dir === "up" ? "text-finance-green" : item._dir === "down" ? "text-finance-red" : "text-muted-foreground";
  const arrow = item._dir === "up" ? "▲" : item._dir === "down" ? "▼" : "•";
  const body = (
    <span
      title={`${item.name} · ${item._freshness}`}
      className="inline-flex shrink-0 items-baseline gap-1.5 whitespace-nowrap rounded px-2 py-0.5 text-xs hover:bg-accent"
    >
      <span className="font-semibold">{displaySymbol(item.symbol)}</span>
      <span className="tabular-nums text-foreground/90">{fmt(item.price)}</span>
      <span className={cn("tabular-nums font-medium", color)}>
        {arrow} {item.changePct == null ? "—" : `${item.changePct >= 0 ? "+" : ""}${item.changePct.toFixed(2)}%`}
      </span>
    </span>
  );
  return href ? <Link href={href}>{body}</Link> : body;
}

const displaySymbol = (s: string) =>
  ({ "^GSPC": "S&P 500", "^IXIC": "NASDAQ", "^DJI": "DOW", "^FTSE": "FTSE", "^GDAXI": "DAX", "^FCHI": "CAC 40", "^N225": "NIKKEI", "^HSI": "HANG SENG", "^SSEC": "SHANGHAI", "^NSEI": "NIFTY 50", "^BSESN": "SENSEX", XAU: "GOLD", WTI: "WTI", BRENT: "BRENT", EURUSD: "EUR/USD", GBPUSD: "GBP/USD", USDJPY: "USD/JPY" }[s] ?? s);

function TickerSkeleton() {
  return (
    <div className="flex gap-3 border-y border-border bg-card/60 px-3 py-2">
      {Array.from({ length: 8 }).map((_, i) => (
        <div key={i} className="h-4 w-28 shrink-0 animate-pulse rounded bg-muted" />
      ))}
    </div>
  );
}
