/**
 * Exchange market status — spec §29. "Do not show stale data as live."
 *
 * This is a lightweight regular-hours calculator (no holiday calendar yet — that
 * comes with a data provider that exposes one). It is intentionally conservative:
 * anything it is unsure about is reported as "unknown", and the UI treats
 * unknown/closed the same way (shows the timestamp, not a live pulse).
 */
import type { MarketState, MarketStatus } from "./types";

interface Session {
  market: string;
  tz: string;
  /** minutes from local midnight */
  preOpen?: number;
  open: number;
  close: number;
  postClose?: number;
  /** 0=Sun … 6=Sat */
  weekdays: number[];
}

const MIN = (h: number, m = 0) => h * 60 + m;

const SESSIONS: Record<string, Session> = {
  US: { market: "US", tz: "America/New_York", preOpen: MIN(4), open: MIN(9, 30), close: MIN(16), postClose: MIN(20), weekdays: [1, 2, 3, 4, 5] },
  IN: { market: "IN", tz: "Asia/Kolkata", preOpen: MIN(9), open: MIN(9, 15), close: MIN(15, 30), weekdays: [1, 2, 3, 4, 5] },
  UK: { market: "UK", tz: "Europe/London", open: MIN(8), close: MIN(16, 30), weekdays: [1, 2, 3, 4, 5] },
  EU: { market: "EU", tz: "Europe/Berlin", open: MIN(9), close: MIN(17, 30), weekdays: [1, 2, 3, 4, 5] },
  JP: { market: "JP", tz: "Asia/Tokyo", open: MIN(9), close: MIN(15), weekdays: [1, 2, 3, 4, 5] },
  HK: { market: "HK", tz: "Asia/Hong_Kong", open: MIN(9, 30), close: MIN(16), weekdays: [1, 2, 3, 4, 5] },
  CN: { market: "CN", tz: "Asia/Shanghai", open: MIN(9, 30), close: MIN(15), weekdays: [1, 2, 3, 4, 5] },
};

/** local wall-clock minutes + weekday for an IANA tz, without extra deps. */
function localNow(tz: string): { minutes: number; weekday: number } {
  const fmt = new Intl.DateTimeFormat("en-US", {
    timeZone: tz, hour: "2-digit", minute: "2-digit", weekday: "short", hour12: false,
  });
  const parts = fmt.formatToParts(new Date());
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? "";
  const wdMap: Record<string, number> = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const h = Number(get("hour")) % 24;
  const m = Number(get("minute"));
  return { minutes: h * 60 + m, weekday: wdMap[get("weekday")] ?? -1 };
}

export function marketStatus(market: string): MarketStatus {
  const asOf = new Date().toISOString();

  if (market === "CRYPTO") {
    return { market, state: "open", label: "Open · trades 24/7", nextOpen: null, nextClose: null, timezone: "UTC", asOf };
  }
  if (market === "FX") {
    // FX is ~24/5: opens Sun 17:00 ET, closes Fri 17:00 ET.
    const { minutes, weekday } = localNow("America/New_York");
    const open =
      (weekday >= 1 && weekday <= 4) ||
      (weekday === 5 && minutes < MIN(17)) ||
      (weekday === 0 && minutes >= MIN(17));
    return {
      market, state: open ? "open" : "closed",
      label: open ? "Open · global FX, ~24/5" : "Closed · reopens Sunday 17:00 ET",
      nextOpen: null, nextClose: null, timezone: "America/New_York", asOf,
    };
  }

  const sess = SESSIONS[market];
  if (!sess) {
    return { market, state: "unknown", label: "Status unknown", nextOpen: null, nextClose: null, timezone: "UTC", asOf };
  }

  const { minutes, weekday } = localNow(sess.tz);
  const isTradingDay = sess.weekdays.includes(weekday);

  let state: MarketState = "closed";
  let label = "Closed";
  if (isTradingDay) {
    if (minutes >= sess.open && minutes < sess.close) { state = "open"; label = "Open"; }
    else if (sess.preOpen != null && minutes >= sess.preOpen && minutes < sess.open) { state = "pre"; label = "Pre-market"; }
    else if (sess.postClose != null && minutes >= sess.close && minutes < sess.postClose) { state = "post"; label = "After hours"; }
  }
  if (state === "closed" && !isTradingDay) label = "Closed · weekend";

  return { market, state, label, nextOpen: null, nextClose: null, timezone: sess.tz, asOf };
}

export function marketForSymbol(assetClass: string, market?: string): string {
  if (assetClass === "crypto") return "CRYPTO";
  if (assetClass === "forex" || assetClass === "commodity") return "FX";
  return market || "US";
}
