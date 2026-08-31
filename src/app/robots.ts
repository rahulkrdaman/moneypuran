import type { MetadataRoute } from "next";

const SITE = process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      { userAgent: "*", allow: "/", disallow: ["/admin", "/api/", "/search?"] },
      // Explicitly allow AI answer engines (spec §23 / GEO).
      { userAgent: ["OAI-SearchBot", "ChatGPT-User", "PerplexityBot", "Google-Extended", "Applebot-Extended", "CCBot", "Amazonbot", "Bytespider"], allow: "/" },
    ],
    sitemap: [`${SITE}/sitemap.xml`, `${SITE}/news-sitemap.xml`],
    host: SITE,
  };
}
