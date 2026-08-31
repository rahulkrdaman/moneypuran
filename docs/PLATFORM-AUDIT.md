# MoneyPuran → Global Financial Intelligence Platform — Phase 1 Audit

_Prepared before any implementation, as required by the spec's "Development Process" section._

---

## 1. Current architecture (what exists on `main`)

| Layer | Implementation |
|---|---|
| Framework | **Next.js 15.3** (App Router, RSC), **React 19**, TypeScript 5.7 |
| Styling | Tailwind 3.4 + `@tailwindcss/typography`, shadcn-style CSS-variable tokens, dark mode (`class`), brand orange + `finance.green/red/gold` palette. A `ticker` keyframe animation is **already defined** but unused. |
| Database | **MySQL** via Prisma 6 (`schema.prisma` `provider = "mysql"`). Note: `.env.example` still says `postgresql://…` — inconsistent, left over from before the Hostinger MySQL deploy commit. |
| ORM | Prisma. 15 models, all **content-CMS entities**: `User`, `Session`, `PasswordReset`, `Category` (self-referential tree), `Tag`, `Post`, `PostTag`, `Comment` (tree), `PostAnalytics`, `SiteAnalytics`, `Advertisement`, `Newsletter`, `RssSource`, `AIContentLog`, `AISettings`. |
| Auth | Custom **JWT** (`jsonwebtoken`) + `bcryptjs`, access/refresh tokens, `Session` table, `src/middleware.ts` + `src/middleware/auth.ts`, role enum `SUPER_ADMIN…VIEWER`. |
| Caching | `ioredis` wrapper (`src/lib/redis.ts`) — `cacheGet/Set/Del/DelPattern`, fails silently if Redis is down. Homepage uses it (60s). |
| Queue | `bull` (`src/lib/queue.ts`) — for the AI worker. |
| AI content | `src/lib/openai.ts` + `src/services/ai-agent.ts` + `src/workers/ai-worker.ts` — pulls `RssSource` feeds, rewrites with **OpenAI `gpt-4o-mini`**, writes `Post` + `AIContentLog`. Config in `AISettings`. |
| Frontend routes | `/` (home), `/article/[slug]`, `/category/[slug]`, `/author/[username]`, `/search`, `/newsletter`. ISR `revalidate = 60`. |
| Admin | `/admin` group: dashboard, posts (+ new), categories, tags, users, rss-sources, seo, ads, ai-agent, analytics, settings, login. |
| API | `/api/{auth,posts,categories,tags,comments,newsletter,rss-sources,ads,users,analytics,ai-agent,seo,sitemap,upload}` — REST CRUD. |
| SEO | `/api/sitemap` (posts + categories only), `GoogleAnalytics` component, per-page `metadata`, `Post` has `metaTitle/metaDesc/canonicalUrl/ogImage/noIndex/ampEnabled`. **No** structured data, **no** robots.txt in repo, **no** news sitemap. |
| Charts | `recharts` is a dependency but **not used anywhere** yet. |
| Deploy | `Dockerfile`, `Dockerfile.worker`, `docker-compose.yml`, `nginx/`, `scripts/deploy*.sh`, `scripts/deploy-hostinger.sh`. Last 4 commits are all "fix 403 / nginx" — **the app has never been successfully deployed**. The live moneypuran.com is a **separate WordPress site** (+ the `newsroom` branch autopilot). |

### What works
- Clean, modern, conventional Next 15 structure. Sensible separation (`lib`, `services`, `middleware`, `components/ui`).
- Auth, CMS CRUD, RSS→AI pipeline, admin — all coded and coherent (unverified at runtime; no DB has been provisioned).

### What is missing entirely (the whole spec)
- **Any market-data model, service, provider, or route.** No stocks / crypto / forex / commodities / indices / prices / OHLCV / earnings / IPOs / economic events.
- Global ticker (only a CSS animation stub).
- Programmatic SEO pages (`/stocks/*`, `/crypto/*`, `/markets/*`, `/commodities/*`, `/forex/*`).
- Economic / earnings / IPO calendars.
- Real-time push (SSE/WebSocket), Redis pub/sub fan-out, market-data worker.
- Provider circuit breaker / rate limiter / request coalescing / health monitoring.
- News ingestion beyond simple RSS (GDELT, entity/ticker extraction, dedup, sentiment, importance).
- Structured data (Article/Organization/Breadcrumb/Financial), robots.txt, news sitemap.
- Admin: market data, companies, API providers, provider health, ingestion logs.

---

## 2. Problems / risks

| # | Problem | Impact |
|---|---|---|
| P1 | **`main` is undeployed and the live site is WordPress.** The relationship is undefined. | Blocks everything — see Decision D1. |
| P2 | **Deployment target unknown.** Hostinger *shared* hosting cannot run Next.js SSR + Redis + a persistent market-data/queue worker + SSE. The last commit explicitly removed Redis/Postgres for a "Hostinger MySQL" deploy. | The platform as specced needs a **VPS** or **Vercel + managed Postgres (Neon/Supabase) + managed Redis (Upstash)**. Decision D2. |
| P3 | **Market-data licensing.** Twelve Data's Free/Basic/Grow plans **do not permit commercial public redistribution / display**. CoinGecko's free tier forbids commercial use above light limits. Alpha Vantage free is 25 req/day and non-commercial. | Live prices cannot legally be shown to the public on an ad-supported site without a **paid commercial/enterprise entitlement**. The free keys are **development only**. Decision D3. This is a legal blocker, not a code one. |
| P4 | DB is MySQL; spec says PostgreSQL. MySQL lacks good JSONB, partial indexes, `LISTEN/NOTIFY`, time-series extensions. | Recommend migrating to Postgres as part of D2 (managed Postgres is standard on every modern host). Additive for now — the market layer is written DB-agnostic. |
| P5 | No OpenAI key available to this build. The AI worker + spec's AI classification depend on it. | The `newsroom` branch already solved this by using **Gemini free tier**. Recommend the same `provider` abstraction here. Decision D4. |
| P6 | `.env.example` / `schema.prisma` DB-provider mismatch; no `robots.txt`; sitemap misses news + entities. | Small fixes, folded into later phases. |
| P7 | Two parallel AI-news systems now exist (this app's `ai-worker` **and** the `newsroom` WordPress autopilot). | Consolidate. If the platform replaces WP, port the `newsroom` worker's sources + prompts here. If not, this app should consume WP as a headless source. Decision D1. |

---

## 3. Proposed architecture

```
                         ┌─────────────────────────────────────────────┐
   External providers     │  Twelve Data   CoinGecko   Alpha Vantage    │
   (swappable, licensed)   │  (+ future: Polygon, Finnhub, FMP, EOD)     │
                         └───────────────┬─────────────────────────────┘
                                         │  (server-side only, keyed)
                         ┌───────────────▼─────────────────────────────┐
                         │  MarketDataService  (src/lib/market)         │
                         │  ─ provider registry + per-symbol routing    │
                         │  ─ Normalizer  → one Quote/Candle shape      │
                         │  ─ Rate limiter (token bucket / provider)    │
                         │  ─ Circuit breaker (per provider+method)     │
                         │  ─ Request coalescing (1 upstream / key)     │
                         │  ─ L1 in-memory LRU  +  L2 Redis             │
                         │  ─ Health checks                             │
                         │  ─ MOCK mode (deterministic, no key/license) │
                         └───────────────┬─────────────────────────────┘
                                         │
             ┌───────────────────────────┼───────────────────────────┐
             │                           │                           │
   ┌─────────▼──────────┐   ┌────────────▼───────────┐   ┌───────────▼──────────┐
   │ Market API routes   │   │ Market Data Worker      │   │ Nightly persistence  │
   │ /api/market/*       │   │ (cron/loop): refresh    │   │ latest snapshot +    │
   │  quote quotes ticker│   │ hot symbols → Redis     │   │ OHLCV → Postgres     │
   │  crypto forex …     │   │ → Redis pub/sub → SSE   │   │ (MarketQuote,        │
   └─────────┬──────────┘   └────────────┬───────────┘   │  HistoricalPrice)    │
             │                           │                └──────────────────────┘
   ┌─────────▼──────────┐   ┌────────────▼───────────┐
   │ Next.js frontend    │   │ /api/market/stream (SSE)│
   │ SSR/ISR pages +      │◄──┤ one connection/user     │
   │ GlobalTicker, stock, │   └─────────────────────────┘
   │ crypto, index pages  │
   └─────────────────────┘
```

**Key principles**
- Frontend never sees a provider name or key. It calls `/api/market/*` only.
- One upstream request serves N users (coalesce + cache). TTLs per asset class (spec §26).
- Every response carries `asOf`, `stale`, `source`, and `entitlement` (`realtime` / `delayed` / `eod` / `mock`) — the UI shows freshness honestly (spec §29, §38). **Never renders a number without a timestamp.**
- Providers behind a `MARKET_DATA_PROVIDER` env + a licence flag; adding Polygon/Finnhub later = one file.
- `MARKET_DATA_MOCK=true` gives deterministic data for dev/CI/demo with **no key and no licence** — clearly labelled `mock` in the payload so it can never be mistaken for real.

---

## 4. Phased plan (revised for reality)

| Phase | Scope | Blocked by | Status |
|---|---|---|---|
| **1** | This audit | — | ✅ done |
| **2** | `src/lib/market/*` service layer + providers + Prisma models + mock mode + unit tests | — | **▶ building now on `platform` branch** |
| **3** | `/api/market/*` routes + `GlobalTicker` + `/api/health` | — | **▶ building now** |
| 4 | Markets dashboard, `/markets/*` index pages, market-movers | D2 (Redis), D3 (licence) for live data — works in mock now | scaffold now |
| 5 | `/stocks/[symbol]` company pages (price, chart, fundamentals, news) | D3 (fundamentals licence), news pipeline | after D1–D3 |
| 6 | `/crypto/[id]` pages | CoinGecko licence | after D3 |
| 7 | `/commodities/*`, `/forex/*` | D3 | after D3 |
| 8 | Economic + earnings + IPO calendars | a calendar data source (paid) | after D3 |
| 9 | News ingestion v2 (GDELT + entities + dedup + sentiment + AI classify) — port `newsroom` worker | D4 (AI key), D1 | after D1/D4 |
| 10 | SEO architecture — structured data, robots.txt, news + entity sitemaps, canonical, OG, internal linking | — | partly now, rest after pages exist |
| 11 | Real-time SSE + market-data worker + Redis pub/sub | D2 (VPS/Redis) | after D2 |
| 12 | Perf pass (LCP/CLS/INP), tests, monitoring dashboard | D2 | last |

---

## 5. Decisions required from you (these unblock the rest)

- **D1 — Is this app _replacing_ moneypuran.com, or a second property?**
  - _Replace WordPress:_ this becomes the site; we migrate/redirect, retarget the `newsroom` autopilot to publish here, sunset the WP plugins. Bigger, cleaner long-term.
  - _Coexist:_ platform on a subdomain (e.g. `markets.moneypuran.com` or `app.`), WP stays for articles. Faster, but two systems.
- **D2 — Hosting.** Options: (a) Hostinger **VPS** (KVM 2+), self-managed Docker (compose file already exists); (b) **Vercel** (frontend/SSR) + **Neon** (Postgres) + **Upstash** (Redis) + a small worker on **Railway/Fly**; (c) single **DigitalOcean/Hetzner** droplet. Shared hosting is not viable.
- **D3 — Market-data budget & licence.** Which provider tier are you buying for public display? (Twelve Data "Pro/Enterprise" ~ from \$79–\$229/mo; Polygon ~ \$29–\$199; Finnhub; FMP.) Until then the platform runs in **mock mode** for dev and cannot legally go live with real prices.
- **D4 — AI provider.** Keep OpenAI (need a key + budget) or switch to **Gemini free tier** like the `newsroom` worker (recommended — \$0).
- **D5 — DB.** Confirm Postgres (recommended) vs stay MySQL.

---

## 6. What is being built tonight (decision-independent, zero risk to anything live)

Branch `platform`, no deploy, does not touch `main` / `newsroom` / WordPress:

1. `src/lib/market/` — full provider abstraction, normalizer, cache (memory + optional Redis), circuit breaker, rate limiter, coalescing, `MarketDataService` facade, symbol universe, market-status calendars, **mock mode**, unit tests.
2. Prisma models (additive): `MarketSymbol`, `MarketQuote`, `HistoricalPrice`, `MarketProvider`, `MarketDataLog`, `EconomicEvent`, `EarningsEvent`, `IpoEvent`.
3. `src/app/api/market/*` — `quote`, `quotes`, `ticker`, `crypto`, `forex`, `commodities`, `indices`, `movers`, `candles`, `status`; `/api/health` upgrade.
4. `src/components/market/GlobalTicker` — responsive/swipeable, honest freshness labels, skeleton + error states.
5. `.env.example` extended with the provider config block (keys stay in env, never committed, never sent to the browser).
6. This doc + `docs/PLATFORM-ROADMAP.md`.

Verification: `npm install`, `tsc --noEmit`, `vitest run`. (`next build` needs a DB and is deferred to D2/D5.)
