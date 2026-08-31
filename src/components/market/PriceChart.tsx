"use client";

import { useEffect, useMemo, useState } from "react";
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

const RANGES = ["1D", "5D", "1M", "6M", "YTD", "1Y", "5Y", "MAX"] as const;
type Range = (typeof RANGES)[number];

type Candle = { t: number; o: number; h: number; l: number; c: number; v: number | null };

export function PriceChart({ symbol }: { symbol: string }) {
  const [range, setRange] = useState<Range>("1M");
  const [candles, setCandles] = useState<Candle[] | null>(null);
  const [err, setErr] = useState(false);

  useEffect(() => {
    let alive = true;
    setCandles(null);
    (async () => {
      try {
        const r = await fetch(`/api/market/candles?symbol=${encodeURIComponent(symbol)}&range=${range}`, { cache: "no-store" });
        const j = await r.json();
        if (!alive) return;
        if (!j?.ok) { setErr(true); return; }
        setCandles(j.data.candles); setErr(false);
      } catch { if (alive) setErr(true); }
    })();
    return () => { alive = false; };
  }, [symbol, range]);

  const data = useMemo(
    () => (candles ?? []).map((c) => ({ t: c.t, price: c.c })),
    [candles],
  );
  const up = data.length > 1 && data[data.length - 1].price >= data[0].price;
  const stroke = up ? "hsl(142 71% 45%)" : "hsl(0 72% 51%)";

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-1">
        {RANGES.map((r) => (
          <button
            key={r}
            onClick={() => setRange(r)}
            className={`rounded px-2 py-1 text-xs font-medium transition-colors ${
              r === range ? "bg-brand-500 text-white" : "text-muted-foreground hover:bg-accent"
            }`}
          >
            {r}
          </button>
        ))}
      </div>
      <div className="h-64 w-full">
        {err && !candles ? (
          <div className="flex h-full items-center justify-center text-sm text-muted-foreground">Chart data unavailable</div>
        ) : !candles ? (
          <div className="h-full w-full animate-pulse rounded bg-muted" />
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
              <defs>
                <linearGradient id="pc" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={stroke} stopOpacity={0.25} />
                  <stop offset="100%" stopColor={stroke} stopOpacity={0} />
                </linearGradient>
              </defs>
              <XAxis dataKey="t" hide />
              <YAxis domain={["dataMin", "dataMax"]} hide />
              <Tooltip
                labelFormatter={(t) => new Date(Number(t)).toLocaleString("en-US")}
                formatter={(v: number) => [v.toLocaleString("en-US", { maximumFractionDigits: 4 }), "Price"]}
                contentStyle={{ fontSize: 12, borderRadius: 8 }}
              />
              <Area type="monotone" dataKey="price" stroke={stroke} strokeWidth={1.5} fill="url(#pc)" />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </div>
      <p className="text-[11px] text-muted-foreground">
        Chart is indicative. Depending on the data plan in use it may be delayed, end-of-day, or demonstration data.
      </p>
    </div>
  );
}
