import { Metadata } from "next";
import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { cacheGet, cacheSet } from "@/lib/redis";
import { NewsCard } from "@/components/ui/NewsCard";
import { TrendingWidget } from "@/components/ui/TrendingWidget";
import { NewsletterBox } from "@/components/ui/NewsletterBox";
import { AdUnit } from "@/components/ads/AdUnit";
import { ChevronRight, Zap } from "lucide-react";

export const metadata: Metadata = {
  title: "MoneyPuran - Finance & Business News India",
  description: "Breaking finance and business news, stock market analysis, economy updates, and investment insights for India.",
};

export const revalidate = 60; // ISR: revalidate every minute

async function getHomeData() {
  const cacheKey = "home:data";
  const cached = await cacheGet<Awaited<ReturnType<typeof fetchHomeData>>>(cacheKey);
  if (cached) return cached;
  const data = await fetchHomeData();
  await cacheSet(cacheKey, data, 60);
  return data;
}

async function fetchHomeData() {
  const [featured, latest, trending, categories, ads] = await Promise.all([
    prisma.post.findMany({
      where: { status: "PUBLISHED", isFeatured: true },
      take: 5, orderBy: { publishedAt: "desc" },
      include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
    }),
    prisma.post.findMany({
      where: { status: "PUBLISHED" }, take: 12, orderBy: { publishedAt: "desc" },
      include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
    }),
    prisma.post.findMany({
      where: { status: "PUBLISHED", isTrending: true }, take: 8, orderBy: { viewCount: "desc" },
      include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
    }),
    prisma.category.findMany({ where: { isActive: true }, take: 8, orderBy: { sortOrder: "asc" } }),
    prisma.advertisement.findMany({ where: { isActive: true, placement: "SIDEBAR" }, orderBy: { priority: "desc" }, take: 3 }),
  ]);
  return { featured, latest, trending, categories, ads };
}

export default async function HomePage() {
  const { featured, latest, trending, categories, ads } = await getHomeData();

  return (
    <div className="container py-6">
      {/* Category Quick Links */}
      <div className="flex items-center gap-2 mb-6 overflow-x-auto pb-2 scrollbar-hide">
        {categories.map((cat) => (
          <Link key={cat.id} href={`/category/${cat.slug}`}
            className="flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border border-border hover:border-brand-500 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-950/30 transition-all"
            style={{ borderColor: cat.color ? `${cat.color}40` : undefined }}>
            {cat.name}
          </Link>
        ))}
      </div>

      {/* Hero Section */}
      {featured.length > 0 && (
        <section className="mb-8">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
            {/* Main featured */}
            <div className="lg:col-span-7">
              <NewsCard post={featured[0] as any} variant="featured" priority />
            </div>
            {/* Secondary featured */}
            <div className="lg:col-span-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
              {featured.slice(1, 5).map((post) => (
                <NewsCard key={post.id} post={post as any} variant="default" />
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Main Content + Sidebar */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-8">
          {/* Latest News */}
          <section>
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-2">
                <Zap className="h-5 w-5 text-brand-600" />
                <h2 className="font-heading font-bold text-xl">Latest News</h2>
              </div>
              <Link href="/latest" className="text-sm text-brand-600 hover:underline flex items-center gap-1">
                View all <ChevronRight className="h-4 w-4" />
              </Link>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {latest.slice(0, 6).map((post) => (
                <NewsCard key={post.id} post={post as any} />
              ))}
            </div>
          </section>

          {/* Category Sections */}
          {categories.slice(0, 3).map(async (cat) => {
            const catPosts = await prisma.post.findMany({
              where: { status: "PUBLISHED", categoryId: cat.id },
              take: 3, orderBy: { publishedAt: "desc" },
              include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
            });
            if (!catPosts.length) return null;
            return (
              <section key={cat.id}>
                <div className="flex items-center justify-between mb-4">
                  <div className="flex items-center gap-2">
                    <div className="h-5 w-1.5 rounded-full" style={{ backgroundColor: cat.color || "#ea580c" }} />
                    <h2 className="font-heading font-bold text-xl">{cat.name}</h2>
                  </div>
                  <Link href={`/category/${cat.slug}`} className="text-sm text-brand-600 hover:underline flex items-center gap-1">
                    More <ChevronRight className="h-4 w-4" />
                  </Link>
                </div>
                <div className="space-y-3">
                  {catPosts.length > 0 && <NewsCard post={catPosts[0] as any} variant="horizontal" />}
                  {catPosts.slice(1).map((p) => <NewsCard key={p.id} post={p as any} variant="compact" />)}
                </div>
              </section>
            );
          })}
        </div>

        {/* Sidebar */}
        <aside className="space-y-6">
          <TrendingWidget posts={trending as any} />
          <NewsletterBox />
          {ads.map((ad) => <AdUnit key={ad.id} ad={ad} />)}
          {/* More Latest */}
          <div className="card p-4">
            <h3 className="font-heading font-bold text-base mb-4 pb-3 border-b border-border">More Stories</h3>
            <div className="space-y-0">
              {latest.slice(6, 12).map((post) => <NewsCard key={post.id} post={post as any} variant="compact" />)}
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}
