"use client";
import { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import { Plus, Search, Filter, Edit, Eye, Trash2, Bot, Star, TrendingUp, AlertCircle, CheckCircle, Clock, Archive } from "lucide-react";
import { formatRelativeDate, formatNumber } from "@/lib/utils";

interface Post { id:string; title:string; slug:string; status:string; postType:string; viewCount:number; publishedAt:string|null; isAiGenerated:boolean; isFeatured:boolean; isTrending:boolean; author:{firstName:string;lastName:string}; category:{name:string;color:string|null}; _count:{comments:number} }

const STATUS_COLORS:Record<string,string> = {
  PUBLISHED:"bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400",
  DRAFT:"bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
  REVIEW:"bg-yellow-100 text-yellow-700 dark:bg-yellow-950 dark:text-yellow-400",
  SCHEDULED:"bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-400",
  ARCHIVED:"bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400",
};

export default function PostsPage() {
  const [posts, setPosts] = useState<Post[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState<string|null>(null);

  const fetchPosts = useCallback(async () => {
    setLoading(true);
    const params = new URLSearchParams({ page:String(page), pageSize:"20" });
    if (search) params.set("q", search);
    if (status) params.set("status", status);
    const res = await fetch(`/api/posts?${params}`);
    const data = await res.json();
    if (data.success) { setPosts(data.data); setTotal(data.meta.total); }
    setLoading(false);
  }, [page, search, status]);

  useEffect(() => { fetchPosts(); }, [fetchPosts]);

  async function handleDelete(id: string) {
    if (!confirm("Delete this post permanently?")) return;
    setDeleting(id);
    await fetch(`/api/posts/${id}`, { method:"DELETE" });
    fetchPosts();
    setDeleting(null);
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div><h1 className="text-2xl font-heading font-bold">Posts</h1><p className="text-muted-foreground text-sm mt-0.5">{total} total articles</p></div>
        <div className="flex gap-2">
          <Link href="/admin/posts/new" className="btn-primary text-sm flex items-center gap-1.5"><Plus className="h-4 w-4" />New Post</Link>
          <Link href="/admin/ai-agent" className="btn-secondary text-sm flex items-center gap-1.5"><Bot className="h-4 w-4" />AI Agent</Link>
        </div>
      </div>

      {/* Filters */}
      <div className="card p-4 flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-48">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <input value={search} onChange={e=>{setSearch(e.target.value);setPage(1);}} placeholder="Search posts..." className="input pl-9 h-9 text-sm" />
        </div>
        <select value={status} onChange={e=>{setStatus(e.target.value);setPage(1);}} className="input h-9 text-sm w-36">
          <option value="">All Status</option>
          {["PUBLISHED","DRAFT","REVIEW","SCHEDULED","ARCHIVED"].map(s=><option key={s} value={s}>{s}</option>)}
        </select>
        <div className="flex items-center gap-2 text-sm text-muted-foreground ml-auto">
          <Filter className="h-4 w-4" /><span>{total} results</span>
        </div>
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-border bg-muted/50 text-left">
              <th className="p-3 font-medium text-muted-foreground w-8"><input type="checkbox" className="rounded" /></th>
              <th className="p-3 font-medium text-muted-foreground">Title</th>
              <th className="p-3 font-medium text-muted-foreground">Category</th>
              <th className="p-3 font-medium text-muted-foreground">Author</th>
              <th className="p-3 font-medium text-muted-foreground">Status</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Views</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Published</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Actions</th>
            </tr></thead>
            <tbody>
              {loading ? (
                Array.from({length:8}).map((_,i)=>(
                  <tr key={i} className="border-b border-border/50">
                    {Array.from({length:8}).map((_,j)=><td key={j} className="p-3"><div className="h-4 bg-muted rounded skeleton" /></td>)}
                  </tr>
                ))
              ) : posts.map(post=>(
                <tr key={post.id} className="border-b border-border/50 hover:bg-muted/30 transition-colors group">
                  <td className="p-3"><input type="checkbox" className="rounded" /></td>
                  <td className="p-3 max-w-xs">
                    <div className="flex items-start gap-2">
                      <div className="flex-1 min-w-0">
                        <p className="font-medium line-clamp-1 group-hover:text-brand-600 transition-colors">{post.title}</p>
                        <div className="flex items-center gap-2 mt-0.5">
                          {post.isAiGenerated && <span className="text-xs text-purple-600 flex items-center gap-0.5"><Bot className="h-3 w-3" />AI</span>}
                          {post.isFeatured && <span className="text-xs text-yellow-600 flex items-center gap-0.5"><Star className="h-3 w-3" />Featured</span>}
                          {post.isTrending && <span className="text-xs text-red-600 flex items-center gap-0.5"><TrendingUp className="h-3 w-3" />Trending</span>}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="p-3">
                    <span className="badge text-xs px-2 py-0.5" style={{backgroundColor:`${post.category.color||"#ea580c"}20`,color:post.category.color||"#ea580c"}}>{post.category.name}</span>
                  </td>
                  <td className="p-3 text-muted-foreground">{post.author.firstName} {post.author.lastName}</td>
                  <td className="p-3"><span className={`badge text-xs px-2 py-0.5 ${STATUS_COLORS[post.status]||STATUS_COLORS.DRAFT}`}>{post.status}</span></td>
                  <td className="p-3 text-right text-muted-foreground">{formatNumber(post.viewCount)}</td>
                  <td className="p-3 text-right text-muted-foreground text-xs">{post.publishedAt ? formatRelativeDate(post.publishedAt) : "—"}</td>
                  <td className="p-3">
                    <div className="flex items-center justify-end gap-1">
                      <Link href={`/admin/posts/${post.id}/edit`} className="h-7 w-7 flex items-center justify-center rounded hover:bg-muted text-muted-foreground hover:text-brand-600 transition-colors" title="Edit"><Edit className="h-3.5 w-3.5" /></Link>
                      <Link href={`/article/${post.slug}`} target="_blank" className="h-7 w-7 flex items-center justify-center rounded hover:bg-muted text-muted-foreground hover:text-green-600 transition-colors" title="View"><Eye className="h-3.5 w-3.5" /></Link>
                      <button onClick={()=>handleDelete(post.id)} disabled={deleting===post.id} className="h-7 w-7 flex items-center justify-center rounded hover:bg-red-50 dark:hover:bg-red-950/30 text-muted-foreground hover:text-red-600 transition-colors" title="Delete"><Trash2 className="h-3.5 w-3.5" /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!loading && posts.length === 0 && (
          <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
            <AlertCircle className="h-10 w-10 mb-3 opacity-40" />
            <p className="font-medium">No posts found</p>
            <p className="text-sm">Try adjusting your filters or create a new post.</p>
          </div>
        )}
        {/* Pagination */}
        {total > 20 && (
          <div className="flex items-center justify-between p-4 border-t border-border">
            <p className="text-sm text-muted-foreground">Showing {(page-1)*20+1}–{Math.min(page*20,total)} of {total}</p>
            <div className="flex gap-2">
              <button disabled={page===1} onClick={()=>setPage(p=>p-1)} className="btn-secondary text-xs h-7 px-3 disabled:opacity-50">← Prev</button>
              <button disabled={page*20>=total} onClick={()=>setPage(p=>p+1)} className="btn-secondary text-xs h-7 px-3 disabled:opacity-50">Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}