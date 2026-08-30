// Free, key-optional market data for the daily "market wrap" posts.
//
//   - Indices / commodities: Yahoo Finance v8 chart endpoint (no key).
//   - Crypto: CoinGecko simple/price (no key).
//
// Everything degrades gracefully: if a symbol can't be fetched it is simply
// omitted. If NOTHING can be fetched for a group, snapshot() returns null and
// the market-wrap source publishes nothing for that slot (better a missing post
// than a wrong one).
//
// Dependency-free (raw fetch).

const UA = { "User-Agent": "Mozilla/5.0 (compatible; moneypuran-newsdesk/1.0)" };
const num = (n) => (typeof n === "number" && isFinite(n) ? n : null);

function fmt(n, dp = 2) {
  if (n == null) return "n/a";
  return n.toLocaleString("en-US", { minimumFractionDigits: dp, maximumFractionDigits: dp });
}
function pct(n) {
  if (n == null) return "n/a";
  const s = n >= 0 ? "+" : "";
  return `${s}${n.toFixed(2)}%`;
}

// One Yahoo symbol -> { name, price, prevClose, changePct, dayHigh, dayLow, currency, asOf }
async function yahoo(symbol) {
  const url = `https://query1.finance.yahoo.com/v8/finance/chart/${encodeURIComponent(symbol)}?range=5d&interval=1d`;
  const r = await fetch(url, { headers: UA });
  if (!r.ok) throw new Error(`yahoo ${symbol} HTTP ${r.status}`);
  const j = await r.json();
  const m = j?.chart?.result?.[0]?.meta;
  if (!m || num(m.regularMarketPrice) == null) throw new Error(`yahoo ${symbol} no price`);
  const price = num(m.regularMarketPrice);
  // Prefer the true prior regular-session close; `chartPreviousClose` is the bar
  // before the requested range window and does not match the headline % move.
  const prev = num(m.previousClose) ?? num(m.chartPreviousClose);
  const changePct = prev ? ((price - prev) / prev) * 100 : num(m.regularMarketChangePercent);
  return {
    name: m.shortName || m.longName || symbol,
    price, prevClose: prev, changePct,
    dayHigh: num(m.regularMarketDayHigh), dayLow: num(m.regularMarketDayLow),
    currency: m.currency || "",
    asOf: m.regularMarketTime ? new Date(m.regularMarketTime * 1000).toISOString() : null,
  };
}

async function crypto(ids = ["bitcoin", "ethereum"]) {
  const url = `https://api.coingecko.com/api/v3/simple/price?ids=${ids.join(",")}&vs_currencies=usd&include_24hr_change=true`;
  const r = await fetch(url, { headers: UA });
  if (!r.ok) throw new Error(`coingecko HTTP ${r.status}`);
  const j = await r.json();
  const label = { bitcoin: "Bitcoin", ethereum: "Ethereum", solana: "Solana", ripple: "XRP", dogecoin: "Dogecoin" };
  return ids.map((id) => j[id] && ({
    name: label[id] || id,
    price: num(j[id].usd),
    changePct: num(j[id].usd_24h_change),
  })).filter(Boolean);
}

async function many(pairs) {
  const out = [];
  for (const [label, symbol] of pairs) {
    try {
      const q = await yahoo(symbol);
      out.push({ label, ...q });
    } catch (e) {
      console.warn(`  market: ${label} (${symbol}) — ${e.message}`);
    }
  }
  return out;
}

const line = (r) =>
  `- ${r.label}: ${fmt(r.price)} (${pct(r.changePct)}${r.prevClose ? `, prev close ${fmt(r.prevClose)}` : ""}` +
  `${r.dayHigh ? `, day range ${fmt(r.dayLow)}–${fmt(r.dayHigh)}` : ""})`;

/**
 * group: "us" | "india" | "crypto-commodities"
 * Returns { title, date, text, movers } or null.
 */
export async function snapshot(group) {
  const date = new Date().toISOString().slice(0, 10);

  if (group === "us") {
    const idx = await many([
      ["S&P 500", "^GSPC"],
      ["Dow Jones Industrial Average", "^DJI"],
      ["Nasdaq Composite", "^IXIC"],
      ["Russell 2000", "^RUT"],
      ["CBOE Volatility Index (VIX)", "^VIX"],
      ["US 10-Year Treasury Yield (%)", "^TNX"],
    ]);
    if (idx.length < 2) return null;
    return {
      title: `US stock market today: S&P 500, Dow and Nasdaq close (${date})`,
      date,
      text:
        `US MARKET DATA — close of ${date} (levels via Yahoo Finance).\n` +
        idx.map(line).join("\n") +
        `\n\nThese are index levels and percentage moves only; no reasons, forecasts or ` +
        `analyst commentary are supplied in this data.`,
      movers: idx,
    };
  }

  if (group === "india") {
    const idx = await many([
      ["BSE Sensex", "^BSESN"],
      ["Nifty 50", "^NSEI"],
      ["Nifty Bank", "^NSEBANK"],
      ["USD / INR", "INR=X"],
    ]);
    if (idx.length < 2) return null;
    return {
      title: `Indian stock market today: Sensex and Nifty 50 close (${date})`,
      date,
      text:
        `INDIAN MARKET DATA — close of ${date} (levels via Yahoo Finance).\n` +
        idx.map(line).join("\n") +
        `\n\nThese are index levels and percentage moves only; no reasons, forecasts or ` +
        `broker commentary are supplied in this data.`,
      movers: idx,
    };
  }

  if (group === "crypto-commodities") {
    const [cg, comm] = await Promise.all([
      crypto(["bitcoin", "ethereum", "solana", "ripple"]).catch(() => []),
      many([
        ["Gold (COMEX front month)", "GC=F"],
        ["Silver (COMEX front month)", "SI=F"],
        ["WTI Crude Oil", "CL=F"],
        ["Brent Crude Oil", "BZ=F"],
        ["US Dollar Index (DXY)", "DX-Y.NYB"],
      ]),
    ]);
    const cryptoLines = cg.map((c) => `- ${c.name}: $${fmt(c.price)} (${pct(c.changePct)} 24h)`);
    if (!cryptoLines.length && comm.length < 2) return null;
    return {
      title: `Crypto and commodities today: Bitcoin, gold and oil (${date})`,
      date,
      text:
        `CRYPTO & COMMODITIES DATA — ${date} (crypto via CoinGecko, commodities via Yahoo Finance).\n` +
        (cryptoLines.length ? "Crypto (24h):\n" + cryptoLines.join("\n") + "\n\n" : "") +
        (comm.length ? "Commodities & FX:\n" + comm.map(line).join("\n") : "") +
        `\n\nPrices and percentage moves only; no reasons, forecasts or analyst commentary ` +
        `are supplied in this data.`,
      movers: [...cg, ...comm],
    };
  }

  return null;
}
