import { Metadata } from "next";
import { notFound } from "next/navigation";
import Image from "next/image";
import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { NewsCard } from "@/components/ui/NewsCard";
import { NewsletterBox } from "@/components/ui/NewsletterBox";
import { formatDate, formatNumber } from "@/lib/utils";
import { Eye, Clock, Share2, Bookmark, MessageCircle, User, ChevronRight } from "lucide-react";

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const post = await prisma.post.findUnique({
    where: { slug, status: "PUBLISHED" },
    include: { author: { select: { firstName: true, lastName: true } }, category: { select: { name: true } } },
  });
  if (!post) return {};
  return {
    title: post.metaTitle || post.title,
    description: post.metaDesc || post.excerpt || undefined,
    authors: [{ name: `${post.author.firstName} ${post.author.lastName}` }],
    keywords: [],
    openGraph: {
      type: "article", title: post.metaTitle || post.title,
      description: post.metaDesc || post.excerpt || undefined,
      images: post.ogImage ? [post.ogImage] : post.featuredImage ? [post.featuredImage] : [],
      publishedTime: post.publishedAt?.toISOString(),
      authors: [`${post.author.firstName} ${post.author.lastName}`],
      section: post.category.name,
    },
    twitter: { card: "summary_large_image", title: post.metaTitle || post.title },
    robots: post.noIndex ? { index: false, follow: false } : { index: true, follow: true },
    alternates: { canonical: post.canonicalUrl || `/article/${post.slug}` },
  };
}

export default async function ArticlePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [post, relatedPosts] = await Promise.all([
    prisma.post.findUnique({
      where: { slug, status: "PUBLISHED" },
      include: {
        author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true, bio:true, twitterHandle:true } },
        category: { select: { id:true, name:true, slug:true, color:true } },
        tags: { include: { tag: true } },
        _count: { select: { comments: true } },
      },
    }),
    prisma.post.findMany({
      where: { slug: { not: slug }, status: "PUBLISHED" },
      take: 4, orderBy: { publishedAt: "desc" },
      include: { author: { select: { id:true, firstName:true, lastName:true, username:true, avatar:true } }, category: { select: { id:true, name:true, slug:true, color:true } }, tags: { include: { tag: true } }, _count: { select: { comments: true } } },
    }),
  ]);

  if (!post) notFound();

  // Increment view count (fire & forget)
  prisma.post.update({ where: { id: post.id }, data: { viewCount: { increment: 1 } } }).catch(() => {});

  const authorName = `${post.author.firstName} ${post.author.lastName}`;

  // Schema.org JSON-LD
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "NewsArticle",
    headline: post.title,
    description: post.excerpt,
    image: post.featuredImage ? [post.featuredImage] : [],
    datePublished: post.publishedAt?.toISOString(),
    dateModified: post.updatedAt.toISOString(),
    author: { "@type": "Person", name: authorName, url: `/author/${post.author.username}` },
    publisher: { "@type": "Organization", name: "MoneyPuran", logo: { "@type": "ImageObject", url: "/logo.png" } },
    mainEntityOfPage: { "@type": "WebPage", "@id": `/article/${post.slug}` },
    articleSection: post.category.name,
    keywords: post.tags.map((t) => t.tag.name).join(", "),
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />
      <div className="container py-8">
        {/* Breadcrumb */}
        <nav className="flex items-center gap-1.5 text-sm text-muted-foreground mb-6">
          <Link href="/" className="hover:text-brand-600 transition-colors">Home</Link>
          <ChevronRight className="h-3.5 w-3.5" />
          <Link href={`/category/${post.category.slug}`} className="hover:text-brand-600 transition-colors">{post.category.name}</Link>
          <ChevronRight className="h-3.5 w-3.5" />
          <span className="text-foreground truncate max-w-48">{post.title}</span>
        </nav>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Article */}
          <article className="lg:col-span-2">
            {/* Meta */}
            <div className="flex items-center gap-2 flex-wrap mb-4">
              <Link href={`/category/${post.category.slug}`}>
                <span className="badge text-sm font-semibold px-3 py-1" style={{ backgroundColor: `${post.category.color}20`, color: post.category.color || "#ea580c" }}>
                  {post.category.name}
                </span>
              </Link>
              {post.isBreaking && <span className="breaking-badge">🔴 BREAKING</span>}
              {post.isTrending && <span className="trending-badge">🔥 TRENDING</span>}
              {post.isAiGenerated && <span className="badge bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300">🤖 AI-Assisted</span>}
              {post.postType === "SPONSORED" && <span className="sponsored-badge">Sponsored</span>}
            </div>

            <h1 className="font-heading text-2xl md:text-4xl font-bold leading-tight mb-4">{post.title}</h1>
            {post.excerpt && <p className="text-lg text-muted-foreground leading-relaxed mb-6 border-l-4 border-brand-500 pl-4">{post.excerpt}</p>}

            {/* Author & Meta */}
            <div className="flex items-center justify-between flex-wrap gap-4 py-4 border-y border-border mb-6">
              <Link href={`/author/${post.author.username}`} className="flex items-center gap-3 group">
                {post.author.avatar ? (
                  <Image src={post.author.avatar} alt={authorName} width={44} height={44} className="rounded-full border-2 border-brand-100" />
                ) : (
                  <div className="h-11 w-11 rounded-full bg-brand-100 dark:bg-brand-950 flex items-center justify-center">
                    <User className="h-5 w-5 text-brand-600" />
                  </div>
                )}
                <div>
                  <p className="font-semibold text-sm group-hover:text-brand-600 transition-colors">{authorName}</p>
                  <time className="text-xs text-muted-foreground">{post.publishedAt ? formatDate(post.publishedAt) : "Draft"}</time>
                </div>
              </Link>
              <div className="flex items-center gap-4 text-sm text-muted-foreground">
                <span className="flex items-center gap-1.5"><Eye className="h-4 w-4" />{formatNumber(post.viewCount)} views</span>
                <span className="flex items-center gap-1.5"><Clock className="h-4 w-4" />{post.readingTime} min read</span>
                <span className="flex items-center gap-1.5"><MessageCircle className="h-4 w-4" />{post._count.comments}</span>
                <button className="flex items-center gap-1.5 hover:text-brand-600 transition-colors">
                  <Share2 className="h-4 w-4" />Share
                </button>
                <button className="flex items-center gap-1.5 hover:text-brand-600 transition-colors">
                  <Bookmark className="h-4 w-4" />Save
                </button>
              </div>
            </div>

            {/* Featured Image */}
            {post.featuredImage && (
              <figure className="mb-8">
                <Image src={post.featuredImage} alt={post.imageAlt || post.title} width={800} height={450}
                  className="w-full rounded-xl object-cover shadow-lg" priority />
                {post.imageCaption && <figcaption className="text-center text-sm text-muted-foreground mt-2 italic">{post.imageCaption}</figcaption>}
              </figure>
            )}

            {/* Content */}
            <div className="article-content prose-finance" dangerouslySetInnerHTML={{ __html: post.content }} />

            {/* Source */}
            {post.sourceUrl && (
              <div className="mt-6 p-3 bg-muted rounded-lg text-sm">
                <span className="text-muted-foreground">Source: </span>
                <a href={post.sourceUrl} target="_blank" rel="noopener noreferrer nofollow" className="text-brand-600 hover:underline">{post.sourceName || post.sourceUrl}</a>
              </div>
            )}

            {/* Tags */}
            {post.tags.length > 0 && (
              <div className="flex items-center flex-wrap gap-2 mt-6 pt-6 border-t border-border">
                <span className="text-sm font-medium text-muted-foreground">Tags:</span>
                {post.tags.map(({ tag }) => (
                  <Link key={tag.id} href={`/tag/${tag.slug}`}
                    className="badge bg-muted text-muted-foreground hover:bg-brand-100 hover:text-brand-700 dark:hover:bg-brand-950 transition-colors text-xs px-3 py-1">
                    #{tag.name}
                  </Link>
                ))}
              </div>
            )}

            {/* Author Box */}
            <div className="mt-8 p-5 card">
              <h3 className="font-semibold text-sm text-muted-foreground uppercase tracking-wide mb-3">About the Author</h3>
              <div className="flex items-start gap-4">
                {post.author.avatar ? (
                  <Image src={post.author.avatar} alt={authorName} width={64} height={64} className="rounded-full flex-shrink-0" />
                ) : (
                  <div className="h-16 w-16 rounded-full bg-brand-100 dark:bg-brand-950 flex items-center justify-center flex-shrink-0">
                    <User className="h-8 w-8 text-brand-600" />
                  </div>
                )}
                <div>
                  <Link href={`/author/${post.author.username}`} className="font-heading font-bold text-lg hover:text-brand-600 transition-colors">{authorName}</Link>
                  {post.author.bio && <p className="text-sm text-muted-foreground mt-1">{post.author.bio}</p>}
                  {post.author.twitterHandle && (
                    <a href={`https://twitter.com/${post.author.twitterHandle}`} target="_blank" rel="noopener noreferrer" className="text-sm text-brand-600 hover:underline mt-1 inline-block">@{post.author.twitterHandle}</a>
                  )}
                </div>
              </div>
            </div>
          </article>

          {/* Sidebar */}
          <aside className="space-y-6">
            <NewsletterBox />
            <div className="card p-4">
              <h3 className="font-heading font-bold text-base mb-4 pb-3 border-b border-border">Related Stories</h3>
              <div className="space-y-0">
                {relatedPosts.map((p) => <NewsCard key={p.id} post={p as any} variant="compact" />)}
              </div>
            </div>
          </aside>
        </div>

        {/* More Stories */}
        {relatedPosts.length > 0 && (
          <section className="mt-12">
            <h2 className="font-heading font-bold text-2xl mb-6">More Stories</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {relatedPosts.map((p) => <NewsCard key={p.id} post={p as any} />)}
            </div>
          </section>
        )}
      </div>
    </>
  );
}
