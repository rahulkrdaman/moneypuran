import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { calculateReadingTime, slugify, generateExcerpt } from "@/lib/utils";
import { cacheDelPattern } from "@/lib/redis";
import { z } from "zod";

const include = {
  author:   { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } },
  category: { select: { id:true, name:true, slug:true, color:true } },
  tags:     { include: { tag: true } },
  _count:   { select: { comments: true } },
};

export async function GET(req: NextRequest) {
  const sp = new URL(req.url).searchParams;
  const page    = parseInt(sp.get("page") || "1");
  const limit   = parseInt(sp.get("limit") || sp.get("pageSize") || "20");
  const search  = sp.get("q") || sp.get("search") || "";

  const where: Record<string, unknown> = {};
  if (sp.get("status"))      where.status     = sp.get("status");
  if (sp.get("categoryId"))  where.categoryId = sp.get("categoryId");
  if (sp.get("authorId"))    where.authorId   = sp.get("authorId");
  if (sp.get("featured") === "true") where.isFeatured = true;
  if (sp.get("trending") === "true") where.isTrending = true;
  if (search) {
    where.OR = [
      { title:   { contains: search } },
      { excerpt: { contains: search } },
    ];
  }

  const [posts, total] = await Promise.all([
    prisma.post.findMany({ where, take: limit, skip: (page - 1) * limit, orderBy: { createdAt: "desc" }, include }),
    prisma.post.count({ where }),
  ]);

  return NextResponse.json({
    success: true,
    data: posts,
    pagination: { page, limit, total, totalPages: Math.ceil(total / limit) },
  });
}

const createSchema = z.object({
  title:        z.string().min(3).max(200),
  content:      z.string().min(10),
  categoryId:   z.string().uuid(),
  status:       z.enum(["DRAFT","REVIEW","SCHEDULED","PUBLISHED","ARCHIVED"]).default("DRAFT"),
  postType:     z.enum(["ARTICLE","ANALYSIS","OPINION","PRESS_RELEASE","SPONSORED"]).default("ARTICLE"),
  excerpt:      z.string().max(500).optional(),
  featuredImage: z.string().optional(),
  isFeatured:   z.boolean().default(false),
  isTrending:   z.boolean().default(false),
  isBreaking:   z.boolean().default(false),
  metaTitle:    z.string().max(70).optional(),
  metaDesc:     z.string().max(160).optional(),
  scheduledAt:  z.string().datetime().optional(),
  tagIds:       z.array(z.string().uuid()).default([]),
  sourceUrl:    z.string().optional(),
  sourceName:   z.string().optional(),
});

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user) return NextResponse.json({ success:false, error:"Unauthorized" }, { status:401 });
  try {
    const data = createSchema.parse(await req.json());
    let slug = slugify(data.title);
    if (await prisma.post.findUnique({ where: { slug } })) slug = `${slug}-${Date.now()}`;
    const post = await prisma.post.create({
      data: {
        ...data,
        slug,
        excerpt:      data.excerpt || generateExcerpt(data.content),
        readingTime:  calculateReadingTime(data.content),
        authorId:     user.userId,
        publishedAt:  data.status === "PUBLISHED" ? new Date() : undefined,
        featuredImage: data.featuredImage || undefined,
        sourceUrl:    data.sourceUrl || undefined,
        tags:         data.tagIds.length ? { create: data.tagIds.map(tagId => ({ tagId })) } : undefined,
      },
      include,
    });
    await cacheDelPattern("home:*");
    return NextResponse.json({ success:true, data:post }, { status:201 });
  } catch (e) {
    if (e instanceof z.ZodError) return NextResponse.json({ success:false, error:e.errors[0].message }, { status:400 });
    console.error(e);
    return NextResponse.json({ success:false, error:"Failed to create post" }, { status:500 });
  }
}
