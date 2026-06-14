import { Metadata } from "next";
import { notFound } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { NewsCard } from "@/components/ui/NewsCard";
import { TrendingWidget } from "@/components/ui/TrendingWidget";
import { NewsletterBox } from "@/components/ui/NewsletterBox";

export async function generateStaticParams() {
  const cats = await prisma.category.findMany({ select: { slug: true } });
  return cats.map((c) => ({ slug: c.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const cat = await prisma.category.findUnique({ where: { slug } });
  if (!cat) return {};
  return {
    title: `${cat.name} News | MoneyPuran`,
    description: cat.description || `Latest ${cat.name} news, analysis and updates.`,
    openGraph: { title: `${cat.name} News`, description: cat.description || "" },
  };
}

export default async function CategoryPage({ params, searchParams }: {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ page?: string }>;
}) {
  const [{ slug }, { page: pageParam }] = await Promise.all([params, searchParams]);
  const page = parseInt(pageParam || "1");
  const pageSize = 12;

  const category = await prisma.category.findUnique({ where: { slug, isActive: true } });
  if (!category) notFound();

  const [posts, total, trending] = await Promise.all([
    prisma.post.findMany({
      where: { categoryId: category.id, status: "PUBLISHED" },
      take: pageSize, skip: (page - 1) * pageSize,
      orderBy: { publishedAt: "desc" },
      include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
    }),
    prisma.post.count({ where: { categoryId: category.id, status: "PUBLISHED" } }),
    prisma.post.findMany({
      where: { isTrending: true, status: "PUBLISHED" }, take: 6, orderBy: { viewCount: "desc" },
      include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
    }),
  ]);

  const totalPages = Math.ceil(total / pageSize);

  return (
    <div className="container py-8">
      {/* Category Header */}
      <div className="mb-8 pb-6 border-b border-border">
        <div className="flex items-center gap-3 mb-2">
          {category.color && <div className="h-6 w-2 rounded-full" style={{ backgroundColor: category.color }} />}
          <h1 className="font-heading font-bold text-3xl">{category.name}</h1>
        </div>
        {category.description && <p className="text-muted-foreground max-w-2xl">{category.description}</p>}
        <p className="text-sm text-muted-foreground mt-2">{total} articles</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            {posts.map((post) => <NewsCard key={post.id} post={post as any} />)}
          </div>
          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-center gap-2">
              {page > 1 && (
                <a href={`/category/${slug}?page=${page - 1}`} className="btn-secondary px-4 py-2 text-sm">← Previous</a>
              )}
              <span className="text-sm text-muted-foreground px-4">Page {page} of {totalPages}</span>
              {page < totalPages && (
                <a href={`/category/${slug}?page=${page + 1}`} className="btn-secondary px-4 py-2 text-sm">Next →</a>
              )}
            </div>
          )}
        </div>
        <aside className="space-y-6">
          <TrendingWidget posts={trending as any} />
          <NewsletterBox />
        </aside>
      </div>
    </div>
  );
}

export const revalidate = 60;
