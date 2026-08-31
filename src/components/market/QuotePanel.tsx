"use client";

import { useEffect, useState } from "react";
import { QuoteChange, FreshnessBadge } from "./QuoteChange";

type Q = {
  symbol: string; name: string; assetClass: string; price: number; currency: string;
  changeAbs: number | null; changePct: number | null; previousClose: number | null;
  open: number | null; dayHigh: number | null; dayLow: number | null;
  yearHigh: number | null; yearLow: number | null; volume: number | null; marketCap: number | null;
  marketState?: string; _freshness: string;
};

function money(n: number | null, c: string) {
  if (n == null || !Number.isFinite(n)) return "—";
  try { return new Intl.NumberFormat("en-US", { style: "currency", currency: c, maximumFractionDigits: n < 5 ? 4 : 2 }).format(n); }
  catch { return String(n); }
}
function compact(n: number | null) {
  if (n == null || !Number.isFinite(n)) return "—";
  return new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 2 }).format(n);
}

export function QuotePanel({ symbol }: { symbol: string }) {
  const [q, setQ] = useState<Q | null>(null);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    const load = async () => {
      try {
        const r = await fetch(`/api/market/quote?symbol=${encodeURIComponent(symbol)}`, { cache: "no-store" });
        const j = await r.json();
        if (!alive) return;
        if (!j?.ok) { setErr(j?.error?.message ?? "unavailable"); return; }
        setQ(j.data); setErr(null);
      } catch { if (alive) setErr("Market data temporarily unavailable"); }
    };
    load();
    const t = setInterval(load, 20_000);
    return () => { alive = false; clearInterval(t); };
  }, [symbol]);

  if (err && !q) {
    return <div className="rounded-lg border border-border p-4 text-sm text-muted-foreground">{err}. <button className="underline" onClick={() => location.reload()}>Retry</button></div>;
  }
  if (!q) {
    return (
      <div className="space-y-3">
        <div className="h-10 w-48 animate-pulse rounded bg-muted" />
        <div className="grid grid-cols-3 gap-3">{Array.from({ length: 6 }).map((_, i) => <div key={i} className="h-12 animate-pulse rounded bg-muted" />)}</div>
      </div>
    );
  }

  const stats: Array<[string, string]> = [
    ["Previous close", money(q.previousClose, q.currency)],
    ["Open", money(q.open, q.currency)],
    ["Day range", q.dayLow != null && q.dayHigh != null ? `${money(q.dayLow, q.currency)} – ${money(q.dayHigh, q.currency)}` : "—"],
    ["52-week range", q.yearLow != null && q.yearHigh != null ? `${money(q.yearLow, q.currency)} – ${money(q.yearHigh, q.currency)}` : "—"],
    ["Volume", compact(q.volume)],
    [q.assetClass === "crypto" ? "Market cap" : "Market cap", compact(q.marketCap)],
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-x-4 gap-y-1">
        <span className="font-heading text-4xl font-bold tabular-nums">{money(q.price, q.currency)}</span>
        <QuoteChange changeAbs={q.changeAbs} changePct={q.changePct} className="text-lg" />
        <div className="flex items-center gap-2">
          <FreshnessBadge label={q._freshness} />
          {q.marketState && <span className="text-xs text-muted-foreground capitalize">· {q.marketState}</span>}
        </div>
      </div>
      <dl className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
        {stats.map(([k, v]) => (
          <div key={k}>
            <dt className="text-xs text-muted-foreground">{k}</dt>
            <dd className="tabular-nums">{v}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
