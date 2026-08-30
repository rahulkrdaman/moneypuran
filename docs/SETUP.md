# MoneyPuran setup checklist

One-time setup. ~60–90 minutes. Do the sections in order. Nothing here touches the
`main` branch (your Next.js app) — this system is entirely on the `newsroom` branch and
talks to WordPress over the REST API.

---

## 1. GitHub — make the schedule work

GitHub only runs `schedule:` triggers from a repo's **default branch**.

- **Recommended:** Settings → Branches → change the default branch to **`newsroom`**.
  (Your Next app stays on `main`, untouched.)
- **Or:** leave `main` as default and just run the workflows by hand from the **Actions**
  tab — `workflow_dispatch` works from any branch. You lose the automatic cron.

## 2. GitHub — the `moneypuran` Environment

Settings → Environments → **New environment** → name it exactly `moneypuran`. Add:

**Secrets**
| Name | Value |
|---|---|
| `GEMINI_API_KEY` | free key from <https://aistudio.google.com/apikey> |
| `WP_APP_PASSWORD` | WordPress Application Password from step 3 (keep the spaces or not, either works) |
| `ANTHROPIC_API_KEY` | *optional* — only if you set `provider: "anthropic"` in `worker/config.json` |

**Variables**
| Name | Value |
|---|---|
| `WP_SITE_URL` | `https://moneypuran.com` |
| `WP_USERNAME` | your WordPress login username (often your email) |
| `EVERGREEN_PER_RUN` | *optional* — `3` by default; raise to `4` to clear the 64-question queue faster |

## 3. WordPress — basics

1. **Users → Profile → Application Passwords** → add one named `newsroom` → copy the
   generated password into `WP_APP_PASSWORD` above. The account needs the **Editor** or
   **Administrator** role (it creates posts, categories and uploads images).
2. **Settings → Permalinks** → **Post name**.
3. **Settings → General** → Timezone `New York` (or your choice), Site Language
   `English (United States)`.
4. **Users → Add New** (optional but good for E-E-A-T): create 2–3 author accounts
   (role *Author*), e.g. "Markets Desk", "Personal Finance Desk". Note their user IDs and
   fill `authorMap` in `worker/config.json`, e.g.
   ```json
   "defaultAuthorId": 3,
   "authorMap": { "US Markets": 3, "Indian Markets": 4, "Personal Finance": 5 }
   ```
5. Categories: the worker auto-creates any it needs, but you can pre-create the 12 in
   `allowedCategories` so they get nice descriptions and menu placement.

## 4. WordPress — Rank Math (recommended)

Install **Rank Math SEO** (free). Then:

- **Titles & Meta → Posts** → *Schema Type* = **Article**, *Article Type* = **News Article**.
- **Titles & Meta → Global Meta** → Knowledge Graph type = **Organization**, set the name
  `MoneyPuran`, upload a square + a rectangular logo.
- **Rank Math → General Settings → Edit robots.txt** — leave to the plugin; the
  `moneypuran-news-seo` plugin adds the news sitemap + AI-crawler lines.
- **Rank Math → Instant Indexing** → enable, and set the **IndexNow API key** to exactly:
  ```
  45d502c0fe78c9b0cd484adca03ee5b4
  ```
  (this matches `worker/config.json` → `indexNowKey` and the `moneypuran-news-seo` plugin).
- **Sitemap** → keep on (posts, pages, categories, authors).

> No Rank Math? The system still works — `moneypuran-news-seo` outputs baseline
> Organization + WebSite schema itself, and the worker just skips the Rank Math meta call.
> You lose per-article SEO title/description control, so Rank Math is worth it.

## 5. WordPress — install the two plugins

For each folder in `plugins/`:

```bash
# from the repo root, on the newsroom branch
cd plugins && zip -r moneypuran-news-seo.zip moneypuran-news-seo && zip -r moneypuran-social.zip moneypuran-social
```

WordPress → **Plugins → Add New → Upload Plugin** → upload each zip → **Activate**.

- **MoneyPuran News SEO** — no settings; it just works. Verify
  `https://moneypuran.com/news-sitemap.xml` returns XML and
  `https://moneypuran.com/45d502c0fe78c9b0cd484adca03ee5b4.txt` returns the key.
- **MoneyPuran Social** — configure under **Settings → MoneyPuran Social** (step 8).

## 6. WordPress — trust pages (Google News + finance E-E-A-T)

Create these **Pages** with these exact slugs (the plugin's footer bar links to them):

| Title | Slug |
|---|---|
| About Us | `about-us` |
| Editorial Policy | `editorial-policy` |
| Corrections Policy | `corrections-policy` |
| Ownership & Funding | `ownership-and-funding` |
| Privacy Policy | `privacy-policy` |
| Contact | `contact` |

Keep them short and truthful. The Ownership page must name the real owner/company.
Set up a `corrections@moneypuran.com` mailbox (or change `correctionsEmail` in
`worker/config.json`).

## 7. Google + Bing

1. **Google Search Console** → add a **Domain** property for `moneypuran.com` (DNS TXT).
   Sitemaps → submit `sitemap_index.xml` (Rank Math) and `news-sitemap.xml`.
2. **Google News Publisher Center** (<https://publishercenter.google.com>) → add a
   publication `MoneyPuran`, language English, country United States, verify the site,
   upload logos. Google auto-generates the publication page.
3. **Bing Webmaster Tools** → add the site (drives IndexNow / ChatGPT search).

## 8. Social auto-post keys (Settings → MoneyPuran Social)

Enable only what you want. All three are free.

- **X:** <https://developer.x.com> → create a Project + App, permissions **Read and write**,
  generate API key/secret + access token/secret. Free tier ≈ 500 posts/month → keep the
  daily limit at 15.
- **Facebook Page:** Graph API Explorer → grant `pages_show_list`, `pages_manage_posts`,
  `pages_read_engagement` → `/me/accounts` for the Page token → extend to ~60 days in the
  Access Token Debugger. Re-do roughly every 50 days.
- **Telegram:** <https://t.me/BotFather> → `/newbot` → token. Add the bot as an
  **administrator** of your channel. Chat ID = `@moneypuran` (public) or the numeric
  `-100…` ID (private). Easiest and highest-volume of the three.

Use the **Send test** button on each before enabling.

## 9. First run

1. **Start conservative:** set `worker/config.json` → `"mode": "pending"` and commit.
   Articles land in **Posts → Pending Review** instead of going live.
2. **Actions → news-desk → Run workflow** (branch `newsroom`). Watch the log.
3. Review the drafts in WordPress. Check: on-topic, accurate vs the linked source,
   sensible headline/slug, image loaded, disclaimer present.
4. Happy? Set `"mode": "publish"`, commit, and let the cron take over. Do the same for
   **evergreen** (it's always `publish` — review its first batch as pending isn't wired,
   or run it once and eyeball the 3 posts, then trust it).
5. Optionally flip the repo to **public** to remove Actions limits + schedule throttling.

---

## Tuning knobs (`worker/config.json`)

| Field | Effect |
|---|---|
| `mode` | `publish` / `pending` |
| `perRunCap` / `dailyCap` | throughput ceilings (`dailyCap` is the real limit) |
| `sources.*.enabled` | turn individual feeds on/off |
| `sources.*.perRunCap` | per-feed cap per run |
| `model` | Gemini model fallback list (or `provider: "anthropic"` + `model: "claude-haiku-4-5"`) |
| `allowedCategories` | the only categories the writer may use |
| `authorMap` | category → WordPress author ID |

Market-recap timing is in `worker/sources/marketwrap.mjs` (`GATES`, UTC hours).

## What this does NOT touch

- The `main` branch / your Next.js app / the custom `moneypuran/v1` REST endpoints.
- Any existing posts or pages (it only creates new ones).
- WordPress core, theme, or user settings (all changes above are done by you in wp-admin).
