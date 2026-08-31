"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { QuoteChange, FreshnessBadge } from "./QuoteChange";

type Row = {
  symbol: string;
  name: string;
  assetClass: string;
  price: number;
  currency: string;
  changeAbs: number | null;
  changePct: number | null;
  _freshness: string;
};

function entityHref(r: Row): string | null {
  const s = r.symbol;
  switch (r.assetClass) {
    case "index": return `/markets/${indexSlug(s)}`;
    case "crypto": return `/crypto/${s.toLowerCase()}`;
    case "forex": return `/forex/${s.slice(0, 3).toLowerCase()}-${s.slice(3).toLowerCase()}`;
    case "commodity": return `/commodities/${commSlug(s)}`;
    case "stock": case "etf": return `/stocks/${s.toLowerCase()}`;
    default: return null;
  }
}
const indexSlug = (s: string) => ({ "^GSPC": "sp-500", "^IXIC": "nasdaq", "^DJI": "dow-jones", "^FTSE": "ftse-100", "^GDAXI": "dax", "^FCHI": "cac-40", "^N225": "nikkei-225", "^HSI": "hang-seng", "^SSEC": "shanghai-composite", "^NSEI": "nifty-50", "^BSESN": "sensex" }[s] ?? s.replace("^", "").toLowerCase());
const commSlug = (s: string) => ({ XAU: "gold", XAG: "silver", WTI: "wti-crude", BRENT: "brent-crude", NG: "natural-gas", HG: "copper" }[s] ?? s.toLowerCase());

function fmt(n: number, c: string) {
  if (!Number.isFinite(n)) return "—";
  try { return new Intl.NumberFormat("en-US", { style: "currency", currency: c, maximumFractionDigits: n < 5 ? 4 : 2 }).format(n); }
  catch { return n.toFixed(2); }
}

export function MarketTable({
  endpoint, title, limit,
}: {
  endpoint: string;          // e.g. "/api/market/indices"
  title?: string;
  limit?: number;
}) {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [err, setErr] = useState(false);

  useEffect(() => {
    let alive = true;
    const load = async () => {
      try {
        const r = await fetch(endpoint, { cache: "no-store" });
        const j = await r.json();
        if (!alive) return;
        if (!j?.ok) { setErr(true); return; }
        setRows((j.data.items as Row[]).slice(0, limit ?? 100));
        setErr(false);
      } catch { if (alive) setErr(true); }
    };
    load();
    const t = setInterval(load, 30_000);
    return () => { alive = false; clearInterval(t); };
  }, [endpoint, limit]);

  if (err && !rows) {
    return (
      <div className="rounded-lg border border-border p-6 text-center text-sm text-muted-foreground">
        Market data temporarily unavailable.{" "}
        <button className="underline" onClick={() => location.reload()}>Retry</button>
      </div>
    );
  }
  if (!rows) return <TableSkeleton title={title} />;

  const fresh = rows[0]?._freshness;

  return (
    <div className="rounded-lg border border-border">
      {title && (
        <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
          <h3 className="text-sm font-semibold">{title}</h3>
          {fresh && <FreshnessBadge label={fresh} />}
        </div>
      )}
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs text-muted-foreground">
            <th className="px-4 py-2 font-medium">Name</th>
            <th className="px-4 py-2 text-right font-medium">Price</th>
            <th className="px-4 py-2 text-right font-medium">Change</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const href = entityHref(r);
            const name = (
              <span className="flex flex-col">
                <span className="font-medium">{r.name}</span>
                <span className="text-xs text-muted-foreground">{r.symbol.replace("^", "")}</span>
              </span>
            );
            return (
              <tr key={r.symbol} className="border-t border-border/60 hover:bg-accent/50">
                <td className="px-4 py-2.5">{href ? <Link href={href}>{name}</Link> : name}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{fmt(r.price, r.currency)}</td>
                <td className="px-4 py-2.5 text-right">
                  <QuoteChange changeAbs={r.changeAbs} changePct={r.changePct} showAbs={false} />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function TableSkeleton({ title }: { title?: string }) {
  return (
    <div className="rounded-lg border border-border">
      {title && <div className="border-b border-border px-4 py-2.5 text-sm font-semibold">{title}</div>}
      <div className="divide-y divide-border/60">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="flex items-center justify-between px-4 py-3">
            <div className="h-4 w-32 animate-pulse rounded bg-muted" />
            <div className="h-4 w-16 animate-pulse rounded bg-muted" />
          </div>
        ))}
      </div>
    </div>
  );
}
