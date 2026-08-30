# MoneyPuran SEO / Google News / AI-answer plan

Goal: rank a brand-new finance site for US (primary) and Indian (secondary) readers,
and get into Google News, Discover and AI answers (ChatGPT/Perplexity/Google AI).

## The three content streams

1. **News Desk** (`worker/run.mjs`) — timely, thin-competition stories from primary
   sources. Fast indexing via IndexNow + news sitemap. This is what gets you into
   Google News and "top stories".
2. **Evergreen Desk** (`worker/evergreen.mjs`) — 64 investing explainers ("what is a
   Roth IRA", "ETF vs mutual fund", "what is the Sensex"). These are the long-tail
   traffic engine and the pages most cited by AI answer engines. Expand
   `worker/evergreen/questions.json` over time — mine Search Console for question
   queries and add them.
3. **Daily market recaps** — "US stock market today", "Sensex and Nifty today",
   "crypto and commodities today". High repeat-search-volume, published every day,
   builds the "check MoneyPuran every morning" habit.

## On-page SEO (handled automatically)

- Focus keyword in slug, H1, first sentence, 2+ H2s, meta description.
- SEO title ≤ 60 chars starting with the keyword, includes a number/year.
- 650–950 word news articles, 800–1100 word explainers, each with an FAQ block.
- Every post gets a real ≥1200px landscape image (press photo → Wikimedia fallback →
  category fallback) so it is Google Discover-eligible.
- Internal links: each post links to its category + 2 recent posts in that category.
- `rel="nofollow"` on outbound source links.

## Schema (the `moneypuran-news-seo` plugin)

- `NewsMediaOrganization` with `sameAs` (all six @moneypuran profiles),
  `publishingPrinciples`, `correctionsPolicy`, `ownershipFundingInfo`.
- `NewsArticle` per post (via Rank Math setting) + `BreadcrumbList` + author `Person`
  with `jobTitle` / `knowsAbout` for E-E-A-T.
- `WebSite` node.
- Baseline Organization + WebSite even with no SEO plugin installed.

## Google News / Discover

- `/news-sitemap.xml` — last 48h of posts, submitted in Search Console.
- Publisher Center publication (auto-generated pages since March 2025).
- Trust pages linked from every page footer (About / Editorial / Corrections /
  Ownership / Privacy / Contact) — Google News eligibility requirement.
- Real bylines, dates, and a corrections policy.

## AI answer engines (GEO / AEO)

- `robots.txt` explicitly **allows** `OAI-SearchBot`, `ChatGPT-User`, `PerplexityBot`,
  `Google-Extended`, `CCBot`, `Applebot-Extended` (via the plugin).
- Evergreen articles are structured as direct question → answer with FAQ schema — the
  format these engines quote.
- IndexNow ping on publish → Bing index (which powers ChatGPT search) within minutes.
- Clear entity: consistent name, logo, `sameAs`, founding info.

## Fast indexing

- Plugin fires IndexNow on every publish; worker also pings IndexNow directly as a
  backup. Covers Bing, Yandex, DuckDuckGo, etc.
- For Google specifically (no instant-index API for general content): submit sitemaps,
  keep publishing quality daily, and use Search Console "Request indexing" for the
  first few cornerstone pages. Consider Rank Math → Instant Indexing → **Google
  Indexing API** (needs a Google Cloud service-account JSON — you paste it in).

## First 90 days — what "working" looks like

- Week 1–2: pages indexed, Search Console impressions start (brand + long-tail).
- Week 3–6: evergreen explainers pick up impressions on "what is / how to" queries;
  news articles appear for niche regulator-story queries.
- Week 6–12: if trust pages + Publisher Center + steady daily publishing are all in
  place, Google News / "Top stories" inclusion becomes possible. Discover is
  image + engagement driven and less predictable.

The single biggest lever is **consistency + accuracy**: publish every day, never let a
wrong number or off-topic post through (that's what `mode: "pending"` is for early on).
