# MoneyPuran 💰

> India's Modern Finance & Business News Platform

Built with **Next.js 15**, **TypeScript**, **Tailwind CSS**, **PostgreSQL**, **Prisma ORM**, **Redis**, and **OpenAI** — featuring an AI agent that automatically fetches, rewrites, and publishes finance news.

---

## 🏗 Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                     MoneyPuran                          │
├─────────────┬─────────────────┬─────────────────────────┤
│  Frontend   │  Admin Panel    │  AI Worker (Cron)       │
│  Next.js 15 │  /admin/*       │  Runs every 30 min      │
│  ISR + SSR  │  Recharts dash  │  Fetch → AI → Publish   │
├─────────────┴─────────────────┴─────────────────────────┤
│                   Next.js API Routes                    │
│   /api/posts  /api/auth  /api/ai-agent  /api/comments   │
├─────────────┬────────────────┬────────────────────────── ┤
│  PostgreSQL  │  Redis         │  OpenAI GPT-4            │
│  (Prisma)    │  (Cache+Queue) │  (Rewrite+Image+Detect)  │
└─────────────┴────────────────┴──────────────────────────┘
```

---

## 🚀 Quick Start

### 1. Clone & configure

```bash
git clone https://github.com/your-org/moneypuran.git
cd moneypuran
cp .env.example .env
# Edit .env with your credentials
```

### 2. One-command setup

```bash
chmod +x scripts/setup.sh && ./scripts/setup.sh
```

### 3. Or manually

```bash
npm install
npx prisma generate
npx prisma migrate dev --name init
npx prisma db seed
npm run dev
```

**Admin login:** `admin@moneypuran.com` / `Admin@123`

---

## 🐳 Docker Deployment

```bash
# Copy and fill env
cp .env.example .env

# Start all services
docker-compose up -d --build

# Run migrations inside container
docker-compose exec app npx prisma migrate deploy
docker-compose exec app npx prisma db seed
```

Services started:
| Service | Port |
|---------|------|
| Next.js App | 3000 (proxied via Nginx) |
| Nginx | **80**, **443** |
| PostgreSQL | 5432 (internal) |
| Redis | 6379 (internal) |
| AI Worker | (cron, no port) |

---

## 📁 Project Structure

```
moneypuran/
├── prisma/
│   ├── schema.prisma          # Full DB schema (17 models)
│   └── seed.ts                # Seed: admin, categories, RSS sources
│
├── src/
│   ├── app/
│   │   ├── (frontend)/        # Public news portal
│   │   │   ├── page.tsx           # Homepage (ISR 60s)
│   │   │   ├── article/[slug]/    # Article detail + Schema.org
│   │   │   ├── category/[slug]/   # Category pages
│   │   │   ├── author/[username]/ # Author profile
│   │   │   ├── search/            # Full-text search
│   │   │   └── newsletter/        # Newsletter landing
│   │   │
│   │   ├── (admin)/           # Admin dashboard
│   │   │   └── admin/
│   │   │       ├── page.tsx       # Dashboard analytics
│   │   │       ├── posts/         # Post CRUD + editor
│   │   │       ├── categories/    # Category management
│   │   │       ├── tags/          # Tag management
│   │   │       ├── users/         # User management
│   │   │       ├── ads/           # Ad management
│   │   │       ├── rss-sources/   # RSS feed management
│   │   │       ├── ai-agent/      # AI control panel
│   │   │       ├── analytics/     # Traffic charts
│   │   │       ├── seo/           # SEO settings
│   │   │       └── settings/      # General settings
│   │   │
│   │   └── api/               # REST API routes
│   │       ├── auth/              # login, logout, me, refresh
│   │       ├── posts/             # CRUD + search
│   │       ├── categories/        # CRUD
│   │       ├── tags/              # CRUD
│   │       ├── users/             # CRUD
│   │       ├── ads/               # CRUD + tracking
│   │       ├── comments/          # CRUD + moderation
│   │       ├── newsletter/        # Subscribe/unsubscribe
│   │       ├── rss-sources/       # CRUD + test
│   │       ├── ai-agent/          # Run agent + logs
│   │       ├── analytics/         # Dashboard stats
│   │       ├── upload/            # Image upload + sharp
│   │       ├── seo/               # SEO settings
│   │       └── sitemap/           # XML sitemap
│   │
│   ├── components/
│   │   ├── layout/            # Header, Footer
│   │   ├── ui/                # NewsCard, SearchBar, CommentSection,
│   │   │                      # TrendingWidget, NewsletterBox, Pagination
│   │   ├── ads/               # AdUnit (AdSense, Image, HTML, Affiliate)
│   │   ├── seo/               # GoogleAnalytics
│   │   └── providers/         # ThemeProvider
│   │
│   ├── lib/
│   │   ├── prisma.ts          # PrismaClient singleton
│   │   ├── auth.ts            # JWT sign/verify, bcrypt, session
│   │   ├── redis.ts           # ioredis client + cache helpers
│   │   ├── queue.ts           # Bull queues (ai, email, rss)
│   │   ├── openai.ts          # GPT-4 rewrite, DALL-E image, dedup
│   │   └── utils.ts           # slugify, formatDate, paginate, etc.
│   │
│   ├── services/
│   │   └── ai-agent.ts        # Full AI pipeline:
│   │                          # RSS fetch → dedup → rewrite → image → publish
│   ├── workers/
│   │   └── ai-worker.ts       # CronJob (30min, Asia/Kolkata)
│   │
│   ├── middleware/
│   │   ├── auth.ts            # withAuth HOC (role-based)
│   │   └── ratelimit.ts       # Redis rate limiter
│   │
│   ├── middleware.ts           # Next.js edge middleware (admin protection)
│   └── types/index.ts         # Full TypeScript interfaces
│
├── nginx/nginx.conf           # SSL, gzip, rate limit, proxy
├── docker-compose.yml         # App + Worker + Postgres + Redis + Nginx
├── Dockerfile                 # Multi-stage Next.js build
├── Dockerfile.worker          # ts-node AI cron worker
└── scripts/setup.sh           # One-command setup
```

---

## 🤖 AI Agent Pipeline

The AI agent runs automatically every 30 minutes:

```
1. Fetch RSS Feeds
   └─ rss-parser reads all active sources from DB

2. Duplicate Detection
   ├─ DB title check (slug similarity)
   └─ OpenAI semantic comparison (threshold: 0.85)

3. AI Rewrite (GPT-4)
   ├─ Unique SEO-friendly article body
   ├─ Catchy title + meta description
   ├─ Relevant tags (up to 5)
   └─ Content quality score (0–1)

4. Image Generation (DALL-E 3)
   └─ Finance-themed featured image (1024×1024)

5. Publish Decision
   ├─ Score ≥ 0.7 + autoPublish ON → PUBLISHED
   ├─ Score < 0.7 → DRAFT (human review)
   └─ autoPublish OFF → always DRAFT

6. Log
   └─ AIContentLog records every step + quality score
```

Configure from **Admin → AI Agent**.

---

## 🗄 Database Schema

Key models:

| Model | Description |
|-------|-------------|
| `Post` | Articles with full SEO fields, AMP flag, view count |
| `Category` | Tree structure (parent/child), color-coded |
| `Tag` | M:N with posts, color-coded |
| `User` | SUPER_ADMIN, ADMIN, EDITOR, AUTHOR, USER |
| `Comment` | Threaded replies, moderation status |
| `RssSource` | Feed URL, fetch interval, error tracking |
| `AIContentLog` | Full audit trail of AI rewrites |
| `Advertisement` | AdSense, Image, HTML, Affiliate — with impression/click tracking |
| `Newsletter` | Subscribers + campaign log |
| `PostAnalytics` | Per-day view counters |
| `SiteAnalytics` | Global daily stats |
| `SeoSettings` | Global SEO config, robots.txt, OG image |
| `AISettings` | AI agent config (model, schedule, thresholds) |

---

## 🔐 Authentication & Roles

JWT-based with HttpOnly cookies + Redis session store.

| Role | Permissions |
|------|-------------|
| SUPER_ADMIN | Everything |
| ADMIN | All except user role changes |
| EDITOR | Posts, categories, tags |
| AUTHOR | Own posts only |
| USER | Comments, newsletter |

---

## 💰 Monetization

- **Google AdSense** — via `adCode` field in AdUnit component
- **Custom Image Ads** — with impression/click tracking API
- **Affiliate Links** — AFFILIATE ad type with custom tracking
- **Sponsored Posts** — `postType: SPONSORED` flag on posts
- **Newsletter Sponsorships** — tracked via campaign model

---

## ⚡ Performance

- **ISR** (Incremental Static Regeneration) — 60s for homepage/categories
- **Redis cache** — article pages, trending lists, category nav
- **Image optimization** — `next/image` + sharp on upload
- **Gzip** in Nginx
- **Rate limiting** — 30 req/min on API, 100 req/min general
- **Core Web Vitals** — optimised with lazy loading, font display swap

---

## 🌐 Environment Variables

See `.env.example` for all variables. Key ones:

```env
DATABASE_URL=postgresql://...
REDIS_URL=redis://...
JWT_ACCESS_SECRET=...        # min 32 chars
JWT_REFRESH_SECRET=...       # min 32 chars
OPENAI_API_KEY=sk-...
NEXT_PUBLIC_SITE_URL=https://moneypuran.com
NEXT_PUBLIC_GA_ID=G-...
```

---

## 📦 Key Dependencies

| Package | Purpose |
|---------|---------|
| `next@15` | Framework (App Router, ISR, Server Components) |
| `prisma` | ORM + migrations |
| `ioredis` | Redis client |
| `bull` | Job queues |
| `openai` | GPT-4 + DALL-E 3 |
| `rss-parser` | RSS feed fetching |
| `bcryptjs` | Password hashing |
| `jsonwebtoken` | JWT auth |
| `sharp` | Image optimization |
| `recharts` | Admin analytics charts |
| `zod` | Schema validation |
| `sanitize-html` | XSS protection for AI content |
| `node-cron` | AI worker scheduling |

---

## 🙏 Credits

Built with ❤️ for the Indian finance news ecosystem.

**MoneyPuran** — *Wealth of knowledge, delivered daily.*
