import Link from "next/link";
import Image from "next/image";
import { TrendingUp, Eye } from "lucide-react";
import { Post } from "@/types";
import { formatNumber, formatRelativeDate } from "@/lib/utils";

export function TrendingWidget({ posts }: { posts: Post[] }) {
  return (
    <div className="card p-4">
      <div className="flex items-center gap-2 mb-4 pb-3 border-b border-border">
        <TrendingUp className="h-5 w-5 text-brand-600" />
        <h3 className="font-heading font-bold text-base">Trending Now</h3>
        <span className="ml-auto badge bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400 animate-pulse text-xs">🔥 HOT</span>
      </div>
      <ol className="space-y-3">
        {posts.map((post, i) => (
          <li key={post.id} className="flex gap-3 group">
            <span className={`flex-shrink-0 h-7 w-7 rounded-full flex items-center justify-center text-sm font-bold
              ${i === 0 ? "bg-brand-600 text-white" : i === 1 ? "bg-brand-500/20 text-brand-600" : "bg-muted text-muted-foreground"}`}>
              {i + 1}
            </span>
            <div className="flex-1 min-w-0">
              <Link href={`/article/${post.slug}`}>
                <h4 className="text-sm font-medium line-clamp-2 group-hover:text-brand-600 transition-colors leading-snug">{post.title}</h4>
              </Link>
              <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
                <span className="text-brand-600">{post.category.name}</span>
                <span>·</span>
                <Eye className="h-3 w-3" />
                <span>{formatNumber(post.viewCount)}</span>
              </div>
            </div>
            {post.featuredImage && (
              <Image src={post.featuredImage} alt={post.title} width={48} height={48}
                className="flex-shrink-0 h-12 w-12 rounded-lg object-cover" loading="lazy" />
            )}
          </li>
        ))}
      </ol>
    </div>
  );
}
