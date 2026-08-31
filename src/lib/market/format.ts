/**
 * Presentation helpers — shared by API responses and components so the whole
 * platform formats prices, changes and freshness identically.
 * Colour is decided here ("up" | "down" | "flat") — components map that to the
 * finance.green / finance.red tokens. Green/red is used ONLY for change (spec §4).
 */
import type { Entitlement, Quote } from "./types";

export function direction(changePct: number | null | undefined): "up" | "down" | "flat" {
  if (changePct == null || !Number.isFinite(changePct) || changePct === 0) return "flat";
  return changePct > 0 ? "up" : "down";
}

export function fmtPrice(value: number, currency = "USD"): string {
  if (!Number.isFinite(value)) return "—";
  const abs = Math.abs(value);
  const dp = abs >= 1000 ? 2 : abs >= 1 ? 2 : abs >= 0.01 ? 4 : 6;
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency", currency, minimumFractionDigits: dp, maximumFractionDigits: dp,
    }).format(value);
  } catch {
    return value.toFixed(dp);
  }
}

export function fmtNumber(value: number | null | undefined, dp = 2): string {
  if (value == null || !Number.isFinite(value)) return "—";
  return new Intl.NumberFormat("en-US", { minimumFractionDigits: dp, maximumFractionDigits: dp }).format(value);
}

export function fmtChangePct(pct: number | null | undefined): string {
  if (pct == null || !Number.isFinite(pct)) return "—";
  return `${pct >= 0 ? "+" : ""}${pct.toFixed(2)}%`;
}

export function fmtCompact(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value)) return "—";
  return new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 2 }).format(value);
}

const FRESHNESS_LABEL: Record<Entitlement, string> = {
  realtime: "Real-time",
  delayed: "15-min delayed",
  eod: "End of day",
  mock: "Demo data",
};

export function freshnessLabel(q: Pick<Quote, "entitlement" | "asOf" | "stale">): string {
  const base = FRESHNESS_LABEL[q.entitlement] ?? "Delayed";
  if (q.stale) {
    const ageMs = Date.now() - Date.parse(q.asOf);
    return `${base} · updated ${relativeTime(ageMs)}`;
  }
  return base;
}

export function relativeTime(ageMs: number): string {
  const s = Math.max(0, Math.round(ageMs / 1000));
  if (s < 60) return `${s}s ago`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m} min ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.round(h / 24)}d ago`;
}

/** Shape returned to the browser — drops nothing sensitive, adds display hints. */
export function toClientQuote(q: Quote) {
  return {
    ...q,
    _dir: direction(q.changePct),
    _freshness: freshnessLabel(q),
  };
}
