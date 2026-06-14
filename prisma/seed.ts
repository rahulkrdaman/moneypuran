import { PrismaClient } from "@prisma/client";
import bcrypt from "bcryptjs";

const prisma = new PrismaClient();

async function main() {
  console.log("🌱 Seeding MoneyPuran database...");

  // ── Super Admin ────────────────────────────────────────────────────────────
  const hashedPw = await bcrypt.hash(process.env.ADMIN_PASSWORD || "MoneyPuran@123", 12);
  const admin = await prisma.user.upsert({
    where:  { email: process.env.ADMIN_EMAIL || "admin@moneypuran.com" },
    update: {},
    create: {
      email:         process.env.ADMIN_EMAIL || "admin@moneypuran.com",
      username:      "admin",
      password:      hashedPw,
      firstName:     "MoneyPuran",
      lastName:      "Admin",
      role:          "SUPER_ADMIN",
      isActive:      true,
      emailVerified: true,
    },
  });
  console.log("✅ Admin user:", admin.email);

  // ── Categories ─────────────────────────────────────────────────────────────
  const cats = [
    { name:"Markets",          slug:"markets",          color:"#16a34a", icon:"📈", sortOrder:1  },
    { name:"Economy",          slug:"economy",          color:"#2563eb", icon:"🏛️", sortOrder:2  },
    { name:"Personal Finance", slug:"personal-finance", color:"#ea580c", icon:"💰", sortOrder:3  },
    { name:"Stocks",           slug:"stocks",           color:"#9333ea", icon:"📊", sortOrder:4  },
    { name:"Crypto",           slug:"crypto",           color:"#f59e0b", icon:"₿",  sortOrder:5  },
    { name:"Real Estate",      slug:"real-estate",      color:"#dc2626", icon:"🏠", sortOrder:6  },
    { name:"Banking",          slug:"banking",          color:"#0891b2", icon:"🏦", sortOrder:7  },
    { name:"Insurance",        slug:"insurance",        color:"#65a30d", icon:"🛡️", sortOrder:8  },
    { name:"Startups",         slug:"startups",         color:"#ec4899", icon:"🚀", sortOrder:9  },
    { name:"Global Business",  slug:"global-business",  color:"#6366f1", icon:"🌍", sortOrder:10 },
  ];
  for (const cat of cats) {
    await prisma.category.upsert({
      where:  { slug: cat.slug },
      create: { ...cat, description: `Latest ${cat.name} news and analysis` },
      update: {},
    });
  }
  console.log(`✅ ${cats.length} categories created`);

  // ── SEO Settings ───────────────────────────────────────────────────────────
  await prisma.seoSettings.upsert({
    where:  { id: "default" },
    create: {
      id:                     "default",
      siteName:               "MoneyPuran",
      tagline:                "India's Trusted Finance & Business News",
      siteUrl:                "https://moneypuran.com",
      defaultMetaTitle:       "MoneyPuran – Finance & Business News India",
      defaultMetaDescription: "Get the latest finance, stock market, business news and investment insights from India and around the world.",
      defaultMetaKeywords:    "finance news, stock market, business, economy, India, investment",
      twitterHandle:          "@moneypuran",
      sitemapEnabled:         true,
      schemaEnabled:          true,
      ampEnabled:             true,
      canonicalEnabled:       true,
      updatedAt:              new Date(),
    },
    update: { updatedAt: new Date() },
  });

  // ── AI Settings ────────────────────────────────────────────────────────────
  await prisma.aISettings.upsert({
    where:  { id: "default" },
    create: {
      id:                  "default",
      model:               "gpt-4o-mini",
      maxTokens:           2000,
      temperature:         0.7,
      autoPublish:         false,
      humanReviewRequired: true,
      qualityThreshold:    0.7,
      duplicateThreshold:  0.85,
      generateImages:      true,
      scheduleEnabled:     true,
      scheduleInterval:    60,
      maxDailyArticles:    50,
      updatedAt:           new Date(),
    },
    update: { updatedAt: new Date() },
  });

  // ── RSS Sources ────────────────────────────────────────────────────────────
  const rssSources = [
    { name:"Economic Times Markets", url:"https://economictimes.indiatimes.com/markets/rssfeeds/1977021501.cms", category:"markets" },
    { name:"Business Standard",      url:"https://www.business-standard.com/rss/markets-106.rss",                 category:"markets" },
    { name:"Livemint",               url:"https://www.livemint.com/rss/markets",                                   category:"markets" },
    { name:"MoneyControl",           url:"https://www.moneycontrol.com/rss/results.xml",                           category:"stocks"  },
    { name:"Bloomberg Markets",      url:"https://feeds.bloomberg.com/markets/news.rss",                           category:"economy" },
  ];
  for (const src of rssSources) {
    await prisma.rssSource.upsert({ where:{ url: src.url }, create:{ ...src, isActive:true }, update:{} });
  }
  console.log(`✅ ${rssSources.length} RSS sources created`);

  // ── Sample Ads ─────────────────────────────────────────────────────────────
  await prisma.advertisement.createMany({
    skipDuplicates: true,
    data: [
      { id:"ad-header-001",  name:"Header Banner",  type:"ADSENSE", placement:"HEADER",        adsenseSlot:"1234567890", isActive:true, priority:10 },
      { id:"ad-sidebar-001", name:"Sidebar Ad 1",   type:"ADSENSE", placement:"SIDEBAR",       adsenseSlot:"0987654321", isActive:true, priority:10 },
      { id:"ad-between-001", name:"Between Posts",  type:"ADSENSE", placement:"BETWEEN_POSTS", adsenseSlot:"1122334455", isActive:true, priority:5  },
    ],
  });

  console.log("\n🎉 Database seeded successfully!");
  console.log(`\n📧 Admin credentials:\n   Email:    ${process.env.ADMIN_EMAIL || "admin@moneypuran.com"}\n   Password: ${process.env.ADMIN_PASSWORD || "MoneyPuran@123"}`);
}

main().catch(console.error).finally(() => prisma.$disconnect());
