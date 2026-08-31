# MoneyPuran Platform — Roadmap & Status

Branch: `platform` (off `main`). Nothing here is deployed. The live moneypuran.com
is still the WordPress site + the `newsroom` autopilot — untouched.

## Done on `platform` (this pass)

### Phase 1 — Audit
- `docs/PLATFORM-AUDIT.md` — current architecture, gap analysis, proposed
  architecture, the 5 blocking decisions (D1–D5), revised phased plan.

### Phase 2 — Market Data service layer  (`src/lib/market/`)
| File | Purpose |
|---|---|
| `types.ts` | Normalized `Quote`, `Candle`, `CandleSeries`, `MarketStatus`, `Movers`, `SymbolMeta`, `MarketDataError`. Every value carries `asOf`, `stale`, `entitlement`, `source`. |
| `config.ts` | Env-driven config, read once, server-only. `MARKET_DATA_MOCK`, per-provider keys/plans, TTLs, `isPublicDisplayAllowed()` licence gate. |
| `symbols.ts` | Curated universe: 13 indices, 30 stocks/ETFs, 15 crypto, 11 FX, 6 commodities. Canonical symbol ↔ slug ↔ per-provider symbol. `resolveSymbol()` handles tickers/names/slugs/aliases. |
| `market-status.ts` | Exchange regular-hours calculator (US/IN/UK/EU/JP/HK/CN + 24/7 crypto + 24/5 FX). Conservative — "unknown" when unsure. |
| `cache.ts` | L1 in-process LRU+TTL (works with **zero Redis**) + optional L2 Redis. Soft-TTL (fresh) / hard-TTL (stale-but-usable) → stale-while-revalidate. |
| `resilience.ts` | `CircuitBreaker`, `TokenBucket`, `coalesce()` (1 upstream call serves N callers), `retry()`, `fetchJson()` with timeout. |
| `providers/base.ts` | `MarketDataProvider` interface + capability descriptor. |
| `providers/mock.ts` | Deterministic demo data (seeded walk). **No key, no licence, no network.** Stamped `entitlement:"mock"`. |
| `providers/twelvedata.ts` | Primary — stocks/ETF/index/FX/commodities/crypto. Batch quotes, time-series. |
| `providers/coingecko.ts` | Preferred for crypto — markets + OHLC. Keyless (dev) or Pro key. |
| `providers/alphavantage.ts` | Fallback only — GLOBAL_QUOTE, FX rate, time-series. Non-commercial → dev-only. |
| `providers/index.ts` | Registry + per-provider breaker + bucket. `providerChain()` = ordered, capability-filtered, circuit-aware. |
| `service.ts` | `MarketDataService` facade: `getQuote`, `getQuotes` (batched by class), `getTicker`, `getCandles` (range→interval), `getMovers`, `getMarketStatus`, `providerHealth`, `marketHealth`. Cache → coalesce → rate-limit → breaker → provider → fallback. |
| `format.ts` | Shared presentation: `direction()`, `fmtPrice/Number/ChangePct/Compact`, `freshnessLabel()`, `toClientQuote()`. |
| `index.ts` | Public barrel — `import { getQuote, … } from "@/lib/market"`. |
| `__tests__/*` | 60+ assertions (vitest): resilience primitives, symbol resolution, mock provider consistency, service in mock mode, format helpers, provider config guards. |

### Phase 3 — API + ticker
- `src/app/api/market/{ticker,quote,quotes,candles,movers,status,crypto,forex,commodities,indices}/route.ts`
  — thin handlers over the service, edge-cacheable envelope, typed errors, **never expose a key**.
- `src/app/api/health/route.ts` — DB + Redis + every provider + licence state (spec §35).
- `src/components/market/GlobalTicker.tsx` — responsive marquee (desktop) / swipe (mobile),
  green/red only for change, "Demo data" pill in mock mode, skeleton + keep-last-known on error.
  Wired into `(frontend)/layout.tsx`.

### Prisma (additive migration — does not touch CMS models)
`MarketSymbol`, `MarketQuote`, `HistoricalPrice`, `MarketProvider`, `MarketDataLog`,
`EconomicEvent`, `EarningsEvent`, `IpoEvent` + `AssetClass` / `MarketEventImportance` enums.

### Config
`.env.example` extended with the full market-data block. `MARKET_DATA_MOCK="true"` is the
default so the app runs and demos with **no keys and no licence**.

## How to run it now (mock mode, no DB writes for the market layer)

```bash
cd moneypuran-app
git checkout platform
npm install
npm test                       # vitest — market layer, no DB needed
# to see the ticker + endpoints you still need a MySQL DB for the CMS pages:
#   cp .env.example .env  &&  set DATABASE_URL  &&  npm run db:push  &&  npm run dev
#   → GET /api/market/ticker      (works with just MARKET_DATA_MOCK=true)
#   → GET /api/market/quote?symbol=NVDA
#   → GET /api/health
```

## Next (blocked — see D1–D5 in PLATFORM-AUDIT.md)

| Phase | Needs |
|---|---|
| 4 Markets dashboard, `/markets/[slug]` index pages, `/markets/movers` | — (works in mock now; live needs D3) |
| 5 `/stocks/[symbol]` company pages (chart, fundamentals, news, financials) | D3 (fundamentals licence) + Phase 9 news |
| 6 `/crypto/[slug]`, 7 `/commodities/[slug]` + `/forex/[slug]` | D3 |
| 8 `/calendar/economic`, `/calendar/earnings`, `/ipo` | a calendar data source (paid) |
| 9 News ingestion v2 — port `newsroom` sources, GDELT, entity/ticker extraction, dedup, sentiment, AI classify | D1 + D4 |
| 10 Structured data, robots.txt, news + entity sitemaps, internal linking | — (do alongside pages) |
| 11 SSE stream + market-data worker + Redis pub/sub | D2 (VPS/managed Redis) |
| 12 Perf (LCP/CLS/INP), monitoring dashboard, load test | D2 |

## Non-negotiables carried through every phase
- Frontend never sees a provider name/key.
- Every displayed number shows its freshness (`Real-time` / `15-min delayed` / `EOD` / `Demo data` / `updated N min ago`). Never a bare price.
- Green/red only for change indicators.
- No fabricated structured data; FAQ schema only with a visible FAQ.
- AI output labelled Bullish/Neutral/Bearish, never a personalised BUY/SELL. Financial disclaimer on every market page.
- One upstream request serves many users (cache + coalesce).
- Provider layer is swappable — buying/adding Polygon/Finnhub/FMP later = one file.
