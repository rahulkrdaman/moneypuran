# MoneyPuran — autonomous business & markets newsroom + SEO

This branch (`newsroom`) holds everything that runs **moneypuran.com** as a hands-off
business, markets and personal-finance news site for a **primarily US audience** (with a
strong India secondary audience). It is adapted from the same engine that runs
aajpatrika.com.

**Strict scope:** stock markets (US + India + global), individual companies, earnings,
IPOs, bonds & rates, central banks, macro data, financial regulation, crypto,
commodities & currencies, funds/ETFs, and practical investing knowledge. Nothing else.

## What's here

| Path | What it does |
|---|---|
| `worker/run.mjs` | **News Desk** — every 2h: pulls official sources (SEC, Federal Reserve, BLS, RBI) + live market data, an LLM writes an original English article, publishes to WordPress with SEO metadata + a real image. |
| `worker/evergreen.mjs` | **Evergreen Desk** — works through `worker/evergreen/questions.json` (64 investing/finance explainers), publishing in-depth "what is / how to" articles. |
| `worker/lib/market.mjs` | Free, key-less market data (Yahoo Finance + CoinGecko) for the daily "US markets today" / "Sensex & Nifty today" / "crypto & commodities today" recaps. |
| `plugins/moneypuran-news-seo/` | WordPress plugin: `/news-sitemap.xml`, AI-crawler robots rules, `NewsMediaOrganization` + breadcrumb + author schema (works with or without Rank Math), IndexNow ping on publish, footer trust bar. |
| `plugins/moneypuran-social/` | WordPress plugin: auto-post new articles to X, a Facebook Page and a Telegram channel using each platform's free API. Self-hosted dlvr.it alternative. |
| `.github/workflows/` | The two schedules. |
| `docs/SETUP.md` | **Start here** — the one-time WordPress + GitHub setup. |
| `docs/sources.md` | Which feeds are used and why it's copyright-safe. |
| `docs/seo-plan.md` | The SEO / Google News / AI-answer plan. |

## Cost

Runs on the **Gemini free tier** (`GEMINI_API_KEY`) → ~$0/month for the writing.
GitHub Actions minutes are the only other cost (free allowance is plenty; making the
repo public removes the limit and the private-repo schedule throttling — no secrets
are committed).

## Quick start

```bash
cd worker
node run.mjs --dry          # dry-run the news desk (no keys needed)
node evergreen.mjs --dry    # dry-run the evergreen desk
```

Then follow [`docs/SETUP.md`](docs/SETUP.md).
