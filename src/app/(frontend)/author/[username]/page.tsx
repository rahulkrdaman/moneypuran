import { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { NewsCard } from "@/components/ui/NewsCard";
import { formatDate, formatNumber } from "@/lib/utils";
import { User, Globe, FileText, Eye } from "lucide-react";

export async function generateMetadata({ params }: { params: Promise<{username:string}> }): Promise<Metadata> {
  const { username } = await params;
  const user = await prisma.user.findUnique({ where:{username}, select:{firstName:true,lastName:true,bio:true} });
  if (!user) return {};
  return { title:`${user.firstName} ${user.lastName} | MoneyPuran`, description:user.bio||`Articles by ${user.firstName} ${user.lastName}` };
}

export default async function AuthorPage({ params }: { params: Promise<{username:string}> }) {
  const { username } = await params;
  const author = await prisma.user.findUnique({
    where: { username, isActive:true },
    include: { _count:{ select:{ posts:{ where:{ status:"PUBLISHED" } } } } },
  });
  if (!author) notFound();

  const posts = await prisma.post.findMany({
    where: { authorId:author.id, status:"PUBLISHED" },
    take: 12, orderBy:{ publishedAt:"desc" },
    include: { author:{select:{id:true,firstName:true,lastName:true,username:true,avatar:true}}, category:{select:{id:true,name:true,slug:true,color:true}}, tags:{include:{tag:true}}, _count:{select:{comments:true}} },
  });

  const totalViews = await prisma.post.aggregate({ where:{authorId:author.id,status:"PUBLISHED"}, _sum:{viewCount:true} });
  const authorName = `${author.firstName} ${author.lastName}`;

  return (
    <div className="container py-8">
      <div className="card p-8 mb-8">
        <div className="flex flex-col sm:flex-row items-start gap-6">
          {author.avatar ? (
            <Image src={author.avatar} alt={authorName} width={96} height={96} className="rounded-full border-4 border-brand-100 dark:border-brand-900 flex-shrink-0" />
          ) : (
            <div className="h-24 w-24 rounded-full bg-gradient-to-br from-brand-400 to-brand-700 flex items-center justify-center flex-shrink-0">
              <User className="h-12 w-12 text-white" />
            </div>
          )}
          <div className="flex-1">
            <h1 className="font-heading text-3xl font-bold">{authorName}</h1>
            <p className="text-brand-600 font-medium mt-0.5">{author.role.replace("_"," ")}</p>
            {author.bio && <p className="text-muted-foreground mt-3 max-w-2xl leading-relaxed">{author.bio}</p>}
            <div className="flex items-center gap-6 mt-4 text-sm text-muted-foreground">
              <span className="flex items-center gap-1.5"><FileText className="h-4 w-4" />{formatNumber(author._count.posts)} articles</span>
              <span className="flex items-center gap-1.5"><Eye className="h-4 w-4" />{formatNumber(totalViews._sum.viewCount||0)} total views</span>
              <span>Member since {formatDate(author.createdAt, {year:"numeric",month:"long"})}</span>
            </div>
            <div className="flex items-center gap-3 mt-4">
              {author.twitterHandle && (
                <a href={`https://twitter.com/${author.twitterHandle}`} target="_blank" rel="noopener noreferrer"
                  className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-brand-600 transition-colors">
                  <span className="font-bold text-sm">𝕏</span> @{author.twitterHandle}
                </a>
              )}
              {author.linkedinUrl && (
                <a href={author.linkedinUrl} target="_blank" rel="noopener noreferrer"
                  className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-brand-600 transition-colors">
                  <span className="font-bold">in</span> LinkedIn
                </a>
              )}
              {author.websiteUrl && (
                <a href={author.websiteUrl} target="_blank" rel="noopener noreferrer"
                  className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-brand-600 transition-colors">
                  <Globe className="h-4 w-4" /> Website
                </a>
              )}
            </div>
          </div>
        </div>
      </div>

      <h2 className="font-heading font-bold text-xl mb-4">Articles by {author.firstName}</h2>
      {posts.length > 0 ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {posts.map(post=><NewsCard key={post.id} post={post as any} />)}
        </div>
      ) : (
        <div className="text-center py-12 text-muted-foreground"><FileText className="h-10 w-10 mx-auto mb-3 opacity-40" /><p>No published articles yet.</p></div>
      )}
    </div>
  );
}
