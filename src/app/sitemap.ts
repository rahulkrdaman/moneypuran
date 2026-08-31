import type { MetadataRoute } from "next";
import { prisma } from "@/lib/prisma";
import { ALL_SYMBOLS } from "@/lib/market";

const SITE = process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com";

/**
 * Full sitemap: static + market entity pages (curated universe) + published
 * articles + categories. Market entity pages are included only for symbols
 * flagged as SEO pages — thin auto-pages are penalised (spec §24).
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const now = new Date();

  const staticRoutes: MetadataRoute.Sitemap = [
    "", "/markets", "/markets/movers", "/investing", "/stocks", "/crypto",
    "/business", "/economy", "/commodities", "/forex", "/news",
    "/calendar/economic", "/calendar/earnings", "/ipo",
  ].map((p) => ({ url: `${SITE}${p}`, lastModified: now, changeFrequency: "hourly" as const, priority: p === "" ? 1 : 0.8 }));

  const entityRoutes: MetadataRoute.Sitemap = ALL_SYMBOLS.map((m) => {
    const base =
      m.assetClass === "index" ? "/markets" :
      m.assetClass === "crypto" ? "/crypto" :
      m.assetClass === "forex" ? "/forex" :
      m.assetClass === "commodity" ? "/commodities" : "/stocks";
    return { url: `${SITE}${base}/${m.slug}`, lastModified: now, changeFrequency: "always" as const, priority: 0.7 };
  });

  let articleRoutes: MetadataRoute.Sitemap = [];
  let categoryRoutes: MetadataRoute.Sitemap = [];
  try {
    const [posts, cats] = await Promise.all([
      prisma.post.findMany({ where: { status: "PUBLISHED" }, select: { slug: true, updatedAt: true }, orderBy: { publishedAt: "desc" }, take: 5000 }),
      prisma.category.findMany({ where: { isActive: true }, select: { slug: true } }),
    ]);
    articleRoutes = posts.map((p) => ({ url: `${SITE}/article/${p.slug}`, lastModified: p.updatedAt, changeFrequency: "weekly" as const, priority: 0.6 }));
    categoryRoutes = cats.map((c) => ({ url: `${SITE}/category/${c.slug}`, lastModified: now, changeFrequency: "daily" as const, priority: 0.7 }));
  } catch {
    // DB not reachable at build → static + entity sitemap still valid
  }

  return [...staticRoutes, ...entityRoutes, ...categoryRoutes, ...articleRoutes];
}
