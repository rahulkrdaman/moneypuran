import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export async function GET() {
  const [posts,categories] = await Promise.all([
    prisma.post.findMany({where:{status:"PUBLISHED"},select:{slug:true,updatedAt:true},orderBy:{publishedAt:"desc"},take:1000}),
    prisma.category.findMany({where:{isActive:true},select:{slug:true}}),
  ]);
  const baseUrl = process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com";
  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>${baseUrl}</loc><changefreq>hourly</changefreq><priority>1.0</priority></url>
  ${categories.map(c=>`<url><loc>${baseUrl}/category/${c.slug}</loc><changefreq>daily</changefreq><priority>0.8</priority></url>`).join("")}
  ${posts.map(p=>`<url><loc>${baseUrl}/article/${p.slug}</loc><lastmod>${new Date(p.updatedAt).toISOString()}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>`).join("")}
</urlset>`;
  return new NextResponse(xml, { headers: { "Content-Type": "application/xml" } });
}