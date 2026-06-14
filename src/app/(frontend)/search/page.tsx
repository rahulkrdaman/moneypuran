import { Metadata } from "next";
import { prisma } from "@/lib/prisma";
import { NewsCard } from "@/components/ui/NewsCard";
import { Search } from "lucide-react";

export const metadata: Metadata = { title: "Search | MoneyPuran", robots: { index: false } };

export default async function SearchPage({ searchParams }: { searchParams: Promise<{q?:string;page?:string}> }) {
  const { q, page: pageParam } = await searchParams;
  const page = parseInt(pageParam||"1");
  const pageSize = 12;

  if (!q) return (
    <div className="container py-20 text-center">
      <Search className="h-16 w-16 text-muted-foreground mx-auto mb-4 opacity-30" />
      <h1 className="font-heading text-2xl font-bold mb-2">Search MoneyPuran</h1>
      <p className="text-muted-foreground mb-8">Search for finance news, stocks, companies, and more.</p>
      <form action="/search" className="flex gap-2 max-w-md mx-auto">
        <input name="q" autoFocus className="input flex-1 text-lg" placeholder="Search articles..." />
        <button type="submit" className="btn-primary px-6">Search</button>
      </form>
    </div>
  );

  const where = {
    status: "PUBLISHED" as const,
    OR: [
      { title:   { contains: q } },
      { excerpt: { contains: q } },
      { content: { contains: q } },
    ],
  };
  const [posts, total] = await Promise.all([
    prisma.post.findMany({ where, take:pageSize, skip:(page-1)*pageSize, orderBy:{publishedAt:"desc"}, include:{author:{select:{id:true,firstName:true,lastName:true,username:true,avatar:true}},category:{select:{id:true,name:true,slug:true,color:true}},tags:{include:{tag:true}},_count:{select:{comments:true}}} }),
    prisma.post.count({ where }),
  ]);
  const totalPages = Math.ceil(total/pageSize);

  return (
    <div className="container py-8">
      <div className="mb-8">
        <form action="/search" className="flex gap-2 max-w-xl mb-4">
          <input name="q" defaultValue={q} className="input flex-1" placeholder="Search..." />
          <button type="submit" className="btn-primary px-6">Search</button>
        </form>
        <p className="text-muted-foreground">{total > 0 ? <><strong>{total}</strong> results for <strong>"{q}"</strong></> : <>No results found for <strong>"{q}"</strong></>}</p>
      </div>
      {posts.length > 0 ? (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
            {posts.map(post=><NewsCard key={post.id} post={post as any} />)}
          </div>
          {totalPages > 1 && (
            <div className="flex items-center justify-center gap-2">
              {page > 1 && <a href={`/search?q=${encodeURIComponent(q)}&page=${page-1}`} className="btn-secondary text-sm">← Previous</a>}
              <span className="text-sm text-muted-foreground">Page {page} of {totalPages}</span>
              {page < totalPages && <a href={`/search?q=${encodeURIComponent(q)}&page=${page+1}`} className="btn-secondary text-sm">Next →</a>}
            </div>
          )}
        </>
      ) : (
        <div className="text-center py-16"><Search className="h-12 w-12 mx-auto mb-4 text-muted-foreground opacity-40" /><p className="text-muted-foreground">Try different keywords or browse our categories.</p></div>
      )}
    </div>
  );
}