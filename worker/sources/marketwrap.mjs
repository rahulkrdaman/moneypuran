// Daily "market wrap" source — NOT a feed. It synthesises up to three items a day
// from live public price data (Yahoo Finance + CoinGecko, no API key) and lets the
// normal article writer turn each into a factual recap:
//
//   us-markets           — after the US close (weekdays)
//   india-markets        — after the India close (weekdays)
//   crypto-commodities   — end of day, every day
//
// Each item is emitted at most once per calendar day (dedup via store on the id
// `marketwrap:<group>:<YYYY-MM-DD>`). If the data can't be fetched, the item is
// skipped rather than published wrong.
import { snapshot } from "../lib/market.mjs";

// UTC hour after which each wrap becomes available. US/India equity closes are
// ~20:00 / ~10:00 UTC; the extra margin covers winter time and data lag.
const GATES = {
  "us-markets":         { afterHourUTC: 21, weekdayOnly: true,  group: "us" },
  "india-markets":      { afterHourUTC: 11, weekdayOnly: true,  group: "india" },
  "crypto-commodities": { afterHourUTC: 21, weekdayOnly: false, group: "crypto-commodities" },
};

export const marketwrap = {
  name: "MoneyPuran market data (Yahoo Finance, CoinGecko)",
  id: "marketwrap",

  async list() {
    const now = new Date();
    const day = now.getUTCDay();          // 0 Sun .. 6 Sat
    const hour = now.getUTCHours();
    const date = now.toISOString().slice(0, 10);
    const out = [];
    for (const [slot, g] of Object.entries(GATES)) {
      if (hour < g.afterHourUTC) continue;
      if (g.weekdayOnly && (day === 0 || day === 6)) continue;
      out.push({ id: `marketwrap:${slot}:${date}`, slot, group: g.group, title: `${slot} ${date}`, url: "https://moneypuran.com/", date });
    }
    return out;
  },

  async fetchOne(item) {
    const snap = await snapshot(item.group);
    if (!snap) {
      return { sourceName: this.name, url: item.url, title: item.title, date: item.date, text: "", image: "" };
    }
    const today = item.date;
    const anyToday = (snap.movers || []).some((m) => (m.asOf || "").slice(0, 10) === today);
    const stalePrefix = anyToday ? "" :
      `NOTE: US/India markets appear to have been CLOSED on ${today} (weekend or holiday). ` +
      `The levels below are from the most recent trading session — write the recap accordingly ` +
      `("markets were closed"; do not imply trading happened today).\n\n`;
    return {
      sourceName: this.name,
      url: item.url,
      title: snap.title,
      date: today,
      text: stalePrefix + snap.text,
      image: "",
    };
  },
};
