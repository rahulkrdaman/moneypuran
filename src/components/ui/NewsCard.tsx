import Link from "next/link";
import Image from "next/image";
import { formatRelativeDate, formatNumber } from "@/lib/utils";
import { Post } from "@/types";
import { Eye, Clock, MessageCircle } from "lucide-react";

interface NewsCardProps {
  post: Post;
  variant?: "default" | "featured" | "compact" | "horizontal";
  priority?: boolean;
}

export function NewsCard({ post, variant = "default", priority = false }: NewsCardProps) {
  const authorName = `${post.author.firstName} ${post.author.lastName}`;

  if (variant === "compact") {
    return (
      <article className="flex gap-3 py-3 border-b border-border last:border-0">
        {post.featuredImage && (
          <Link href={`/article/${post.slug}`} className="flex-shrink-0">
            <Image src={post.featuredImage} alt={post.imageAlt || post.title} width={80} height={60}
              className="rounded-lg object-cover w-20 h-15" loading="lazy" />
          </Link>
        )}
        <div className="flex-1 min-w-0">
          {post.isBreaking && <span className="breaking-badge mb-1">BREAKING</span>}
          <Link href={`/article/${post.slug}`} className="block">
            <h3 className="text-sm font-semibold line-clamp-2 hover:text-brand-600 transition-colors leading-snug">{post.title}</h3>
          </Link>
          <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
            <Link href={`/category/${post.category.slug}`} className="text-brand-600 font-medium hover:underline">{post.category.name}</Link>
            <span>·</span>
            <time dateTime={String(post.publishedAt)}>{formatRelativeDate(post.publishedAt!)}</time>
          </div>
        </div>
      </article>
    );
  }

  if (variant === "horizontal") {
    return (
      <article className="card group flex gap-4 p-4 overflow-hidden">
        {post.featuredImage && (
          <Link href={`/article/${post.slug}`} className="flex-shrink-0">
            <Image src={post.featuredImage} alt={post.imageAlt || post.title} width={160} height={110}
              className="rounded-lg object-cover w-40 h-28 group-hover:scale-105 transition-transform duration-300" loading="lazy" />
          </Link>
        )}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 mb-2">
            <Link href={`/category/${post.category.slug}`}>
              <span className="badge text-xs font-medium" style={{ backgroundColor: `${post.category.color}20`, color: post.category.color || "#ea580c" }}>
                {post.category.name}
              </span>
            </Link>
            {post.isBreaking && <span className="breaking-badge">BREAKING</span>}
            {post.isTrending && <span className="trending-badge">🔥 TRENDING</span>}
          </div>
          <Link href={`/article/${post.slug}`}>
            <h2 className="font-heading font-bold text-base line-clamp-2 hover:text-brand-600 transition-colors mb-2 leading-snug">{post.title}</h2>
          </Link>
          {post.excerpt && <p className="text-sm text-muted-foreground line-clamp-2 mb-3">{post.excerpt}</p>}
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <div className="flex items-center gap-3">
              <Link href={`/author/${post.author.username}`} className="hover:text-brand-600 transition-colors font-medium">{authorName}</Link>
              <time dateTime={String(post.publishedAt)}>{formatRelativeDate(post.publishedAt!)}</time>
            </div>
            <div className="flex items-center gap-3">
              <span className="flex items-center gap-1"><Eye className="h-3 w-3" />{formatNumber(post.viewCount)}</span>
              <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{post.readingTime}m</span>
            </div>
          </div>
        </div>
      </article>
    );
  }

  if (variant === "featured") {
    return (
      <article className="card group relative overflow-hidden h-full">
        <Link href={`/article/${post.slug}`} className="block h-full">
          <div className="relative h-72 md:h-80 overflow-hidden">
            {post.featuredImage ? (
              <Image src={post.featuredImage} alt={post.imageAlt || post.title} fill
                className="object-cover group-hover:scale-105 transition-transform duration-500" priority={priority} />
            ) : (
              <div className="w-full h-full bg-gradient-to-br from-brand-600 to-brand-800 flex items-center justify-center">
                <span className="text-white text-6xl opacity-30">₹</span>
              </div>
            )}
            <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent" />
            <div className="absolute bottom-0 left-0 right-0 p-5">
              <div className="flex items-center gap-2 mb-2">
                <span className="badge text-xs font-semibold bg-brand-600 text-white">{post.category.name}</span>
                {post.isBreaking && <span className="breaking-badge">BREAKING</span>}
                {post.isTrending && <span className="trending-badge">🔥 HOT</span>}
              </div>
              <h2 className="font-heading font-bold text-white text-xl md:text-2xl leading-tight line-clamp-3 mb-2">{post.title}</h2>
              <div className="flex items-center gap-3 text-white/70 text-xs">
                <span>{authorName}</span><span>·</span>
                <time>{formatRelativeDate(post.publishedAt!)}</time>
                <span className="ml-auto flex items-center gap-1"><Eye className="h-3 w-3" />{formatNumber(post.viewCount)}</span>
              </div>
            </div>
          </div>
        </Link>
      </article>
    );
  }

  return (
    <article className="card group overflow-hidden flex flex-col h-full">
      {post.featuredImage && (
        <Link href={`/article/${post.slug}`} className="block overflow-hidden">
          <Image src={post.featuredImage} alt={post.imageAlt || post.title} width={400} height={220}
            className="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300" loading={priority ? "eager" : "lazy"} />
        </Link>
      )}
      <div className="p-4 flex-1 flex flex-col">
        <div className="flex items-center gap-2 mb-2">
          <Link href={`/category/${post.category.slug}`}>
            <span className="badge text-xs font-medium" style={{ backgroundColor: `${post.category.color}15`, color: post.category.color || "#ea580c" }}>
              {post.category.name}
            </span>
          </Link>
          {post.isBreaking && <span className="breaking-badge text-xs">BREAKING</span>}
        </div>
        <Link href={`/article/${post.slug}`} className="flex-1">
          <h2 className="font-heading font-bold text-base line-clamp-2 hover:text-brand-600 transition-colors leading-snug mb-2">{post.title}</h2>
          {post.excerpt && <p className="text-sm text-muted-foreground line-clamp-2">{post.excerpt}</p>}
        </Link>
        <div className="flex items-center justify-between mt-3 pt-3 border-t border-border text-xs text-muted-foreground">
          <div className="flex items-center gap-2">
            {post.author.avatar && <Image src={post.author.avatar} alt={authorName} width={20} height={20} className="rounded-full" />}
            <Link href={`/author/${post.author.username}`} className="hover:text-brand-600 font-medium truncate max-w-24">{authorName}</Link>
          </div>
          <div className="flex items-center gap-3">
            <time>{formatRelativeDate(post.publishedAt!)}</time>
            <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{post.readingTime}m</span>
            <span className="flex items-center gap-1"><MessageCircle className="h-3 w-3" />{post._count?.comments || 0}</span>
          </div>
        </div>
      </div>
    </article>
  );
}
