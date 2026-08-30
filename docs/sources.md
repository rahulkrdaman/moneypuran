# News sources — what we ingest and why it's safe

The News Desk only writes from **official primary sources** (government / regulator
press releases) and **public market data**. It never scrapes or rewrites other news
outlets (Reuters, Bloomberg, CNBC, Moneycontrol, …). That is a deliberate choice:
re-reporting other publishers' work at scale is the "scaled content abuse" that gets
sites demoted or removed from Google News, and it is a copyright risk.

## Enabled by default

| Key | Source | Feed | Notes |
|---|---|---|---|
| `marketwrap` | Yahoo Finance + CoinGecko | live JSON, no key | Daily "US markets today", "Sensex & Nifty today", "crypto & commodities today" recaps built from index levels and % moves only. Gated by time of day in `sources/marketwrap.mjs`. |
| `sec` | U.S. Securities and Exchange Commission | `sec.gov/news/pressreleases.rss` | Enforcement, rulemaking, charges. Public domain. |
| `fed` | U.S. Federal Reserve Board | `federalreserve.gov/feeds/press_all.xml` | FOMC statements & minutes, enforcement, supervision. Public domain. |
| `bls` | U.S. Bureau of Labor Statistics | `empsit` / `cpi` / `ppi` Atom feeds | The jobs report, CPI and PPI — the three biggest US macro releases. Public domain. |
| `rbi` | Reserve Bank of India | `rbi.org.in/pressreleases_rss.xml` | Monetary policy, banking action, forex. Government content. |
| `rbi_notif` | Reserve Bank of India | `rbi.org.in/notifications_rss.xml` | Regulatory circulars; full text in the feed. |

## Available but disabled

| Key | Why off |
|---|---|
| `sebi` | SEBI's only public RSS (`sebirss.xml`) is an enforcement/recovery-order feed, not a press-release feed — mostly procedural noise. Turn on in `config.json` only if you wire `feeds` to a better source. |

## Ideas for later

- **NSE / BSE corporate announcements** — rich Indian-market signal, but both APIs are
  heavily bot-protected; would need a resilient fetch layer.
- **US Treasury / BEA** — no clean RSS; would need HTML scraping of the press pages.
- **Company 8-K / results filings via SEC EDGAR** — `data.sec.gov` full-text search API
  is open and would give per-company earnings coverage.
- **CFTC, FDIC, OCC** — more US financial-regulator primary sources with RSS.

## Attribution

Every article ends with a "Based on information published by <source>" line linking back
to the original release (`rel="nofollow"`), plus a not-investment-advice disclaimer.
Market recaps name Yahoo Finance / CoinGecko as the data source.
