import Parser from "rss-parser";
import { prisma } from "@/lib/prisma";
import { rewriteArticle, detectDuplicate, generateImage } from "@/lib/openai";
import { slugify, calculateReadingTime } from "@/lib/utils";
import sanitizeHtml from "sanitize-html";

const parser = new Parser({ timeout: 10000 });

export interface RssItem {
  title: string;
  content: string;
  link: string;
  pubDate?: string;
  author?: string;
}

// ─── Fetch RSS Feed ───────────────────────────────────────────────────────────

export async function fetchRssFeed(url: string): Promise<RssItem[]> {
  const feed = await parser.parseURL(url);
  return feed.items.map((item) => ({
    title:   item.title || "",
    content: item["content:encoded"] || item.content || item.summary || "",
    link:    item.link || "",
    pubDate: item.pubDate,
    author:  item.author || item.creator,
  })).filter((i) => i.title && i.link);
}

// ─── Duplicate Check ──────────────────────────────────────────────────────────

export async function isDuplicate(title: string, content: string): Promise<boolean> {
  const existingLog = await prisma.aIContentLog.findFirst({
    where: { originalTitle: { contains: title.substring(0, 50) } },
  });
  if (existingLog) return true;

  const recentPosts = await prisma.post.findMany({
    where:  { createdAt: { gte: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000) } },
    take:   20,
    select: { title: true },
  });

  for (const post of recentPosts) {
    const sim = await detectDuplicate(title, post.title);
    if (sim > 0.85) return true;
  }
  return false;
}

// ─── Process Single Article ───────────────────────────────────────────────────

export async function processArticle(
  item: RssItem,
  sourceId: string,
  sourceName: string,
  categoryLabel?: string,
): Promise<{ success: boolean; postId?: string; error?: string }> {
  const startTime = Date.now();

  const log = await prisma.aIContentLog.create({
    data: {
      originalTitle: item.title,
      sourceUrl:     item.link,
      sourceName,
      rssSourceId:   sourceId,
      status:        "PROCESSING",
    },
  });

  try {
    if (await isDuplicate(item.title, item.content)) {
      await prisma.aIContentLog.update({
        where: { id: log.id },
        data:  { status: "FAILED", errorMessage: "Duplicate content detected" },
      });
      return { success: false, error: "Duplicate" };
    }

    const settings     = await prisma.aISettings.findFirst();
    const categoryName = categoryLabel || "Finance";
    const rewritten    = await rewriteArticle(item.title, item.content, categoryName);

    // Quality check
    if (rewritten.qualityScore < (settings?.qualityThreshold || 0.7)) {
      await prisma.aIContentLog.update({
        where: { id: log.id },
        data:  {
          status:       "HUMAN_REVIEW",
          rewrittenTitle: rewritten.title,
          qualityScore: rewritten.qualityScore,
          errorMessage: "Quality below threshold — needs human review",
        },
      });
      return { success: false, error: "Quality threshold not met" };
    }

    // Generate image
    let imageUrl: string | null = null;
    if (settings?.generateImages) {
      imageUrl = await generateImage(rewritten.title);
    }

    // Unique slug
    let slug = slugify(rewritten.slug || rewritten.title);
    if (await prisma.post.findUnique({ where: { slug } })) slug = `${slug}-${Date.now()}`;

    // Default author & category
    const defaultAuthor = await prisma.user.findFirst({
      where: { role: { in: ["SUPER_ADMIN", "ADMIN"] } },
    });
    if (!defaultAuthor) throw new Error("No admin user found");

    const defaultCategory = await prisma.category.findFirst({ where: { isActive: true } });
    if (!defaultCategory) throw new Error("No category found");

    // Tags
    const tagIds = await Promise.all(
      (rewritten.tags || []).slice(0, 8).map(async (tagName: string) => {
        const tagSlug = slugify(tagName);
        const tag = await prisma.tag.upsert({
          where:  { slug: tagSlug },
          create: { name: tagName, slug: tagSlug },
          update: {},
        });
        return tag.id;
      })
    );

    const cleanContent = sanitizeHtml(rewritten.content, {
      allowedTags:       sanitizeHtml.defaults.allowedTags.concat(["img", "h2", "h3", "h4", "figure", "figcaption"]),
      allowedAttributes: { ...sanitizeHtml.defaults.allowedAttributes, img: ["src", "alt", "loading"] },
    });

    const autoPublish = settings?.autoPublish && !settings?.humanReviewRequired;

    const post = await prisma.post.create({
      data: {
        title:         rewritten.title,
        slug,
        excerpt:       rewritten.excerpt,
        content:       cleanContent,
        featuredImage: imageUrl,
        status:        autoPublish ? "PUBLISHED" : "REVIEW",
        authorId:      defaultAuthor.id,
        categoryId:    defaultCategory.id,
        metaTitle:     rewritten.title,
        metaDesc:      rewritten.metaDescription,
        isAiGenerated: true,
        aiQualityScore: rewritten.qualityScore,
        readingTime:   calculateReadingTime(cleanContent),
        sourceUrl:     item.link,
        sourceName,
        publishedAt:   autoPublish ? new Date() : null,
        tags: { create: tagIds.map((tagId) => ({ tagId })) },
      },
    });

    // Update log with results
    await prisma.aIContentLog.update({
      where: { id: log.id },
      data:  {
        postId:          post.id,
        status:          "COMPLETED",
        rewrittenTitle:  rewritten.title,
        qualityScore:    rewritten.qualityScore,
        tokensUsed:      rewritten.tokensUsed,
        processingTimeMs: Date.now() - startTime,
      },
    });

    // Update source stats (postsGenerated = new field name)
    await prisma.rssSource.update({
      where: { id: sourceId },
      data:  {
        postsGenerated: { increment: 1 },
        lastFetchedAt:  new Date(),
        lastError:      null,
      },
    });

    return { success: true, postId: post.id };
  } catch (error) {
    const errorMsg = error instanceof Error ? error.message : "Unknown error";
    await prisma.aIContentLog.update({
      where: { id: log.id },
      data:  { status: "FAILED", errorMessage: errorMsg, processingTimeMs: Date.now() - startTime },
    });
    await prisma.rssSource.update({
      where: { id: sourceId },
      data:  { lastFetchedAt: new Date(), lastError: errorMsg },
    });
    return { success: false, error: errorMsg };
  }
}

// ─── Process All Active Sources ───────────────────────────────────────────────

export async function processAllRssSources(): Promise<void> {
  const sources  = await prisma.rssSource.findMany({ where: { isActive: true } });
  const settings = await prisma.aISettings.findFirst();
  const maxDaily = settings?.maxDailyArticles || 50;

  const todayCount = await prisma.aIContentLog.count({
    where: { createdAt: { gte: new Date(new Date().setHours(0, 0, 0, 0)) } },
  });

  if (todayCount >= maxDaily) {
    console.log(`Daily limit reached (${todayCount}/${maxDaily})`);
    return;
  }

  let processed = todayCount;

  for (const source of sources) {
    if (processed >= maxDaily) break;
    try {
      const items = await fetchRssFeed(source.url);
      console.log(`📡 ${source.name}: ${items.length} items`);

      for (const item of items.slice(0, 5)) {
        if (processed >= maxDaily) break;
        // Pass category as a plain string label
        await processArticle(item, source.id, source.name, source.category || undefined);
        processed++;
        await new Promise((r) => setTimeout(r, 2000));
      }
    } catch (error) {
      const errorMsg = error instanceof Error ? error.message : String(error);
      console.error(`❌ ${source.name}: ${errorMsg}`);
      await prisma.rssSource.update({
        where: { id: source.id },
        data:  { lastFetchedAt: new Date(), lastError: errorMsg },
      });
    }
  }
}
