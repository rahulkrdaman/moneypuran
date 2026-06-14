"use client";
import { useState, useEffect } from "react";
import Link from "next/link";
import { BarChart3, FileText, Users, Eye, Bot, TrendingUp, Clock, CheckCircle, XCircle, AlertCircle, Activity, ArrowUpRight, Newspaper } from "lucide-react";
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, PieChart, Pie, Cell } from "recharts";
import { formatNumber, formatRelativeDate } from "@/lib/utils";

const COLORS = ["#ea580c", "#2563eb", "#16a34a", "#d97706", "#9333ea", "#0891b2"];

interface Stats {
  totalPosts: number; publishedPosts: number; totalViews: number;
  totalUsers: number; totalComments: number; aiArticlesToday: number;
  newsletterSubscribers: number;
  recentPosts: { id: string; title: string; slug: string; status: string; viewCount: number; publishedAt: string | null }[];
  viewsChart: { date: string; views: number }[];
  categoryDistribution: { name: string; count: number; color?: string }[];
}

export default function AdminDashboard() {
  const [stats, setStats] = useState<Stats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch("/api/analytics/dashboard").then(r => r.json()).then(d => {
      if (d.success) setStats(d.data);
    }).finally(() => setLoading(false));
  }, []);

  if (loading) return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {Array.from({length:8}).map((_,i) => <div key={i} className="card p-5 h-28 skeleton" />)}
      </div>
    </div>
  );

  const statCards = [
    { label: "Total Posts", value: formatNumber(stats?.totalPosts || 0), icon: FileText, color: "text-blue-500", bg: "bg-blue-50 dark:bg-blue-950/30", href: "/admin/posts" },
    { label: "Published", value: formatNumber(stats?.publishedPosts || 0), icon: CheckCircle, color: "text-green-500", bg: "bg-green-50 dark:bg-green-950/30", href: "/admin/posts?status=PUBLISHED" },
    { label: "Total Views", value: formatNumber(stats?.totalViews || 0), icon: Eye, color: "text-brand-500", bg: "bg-orange-50 dark:bg-orange-950/30", href: "/admin/analytics" },
    { label: "Users", value: formatNumber(stats?.totalUsers || 0), icon: Users, color: "text-purple-500", bg: "bg-purple-50 dark:bg-purple-950/30", href: "/admin/users" },
    { label: "AI Articles Today", value: formatNumber(stats?.aiArticlesToday || 0), icon: Bot, color: "text-cyan-500", bg: "bg-cyan-50 dark:bg-cyan-950/30", href: "/admin/ai-agent" },
    { label: "Comments", value: formatNumber(stats?.totalComments || 0), icon: Activity, color: "text-yellow-500", bg: "bg-yellow-50 dark:bg-yellow-950/30", href: "/admin/posts" },
    { label: "Newsletter Subs", value: formatNumber(stats?.newsletterSubscribers || 0), icon: Newspaper, color: "text-pink-500", bg: "bg-pink-50 dark:bg-pink-950/30", href: "/admin/settings" },
    { label: "Trending Posts", value: "Active", icon: TrendingUp, color: "text-red-500", bg: "bg-red-50 dark:bg-red-950/30", href: "/admin/posts?trending=true" },
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-heading font-bold">Dashboard</h1>
          <p className="text-muted-foreground text-sm mt-0.5">Welcome back! Here's what's happening with MoneyPuran.</p>
        </div>
        <div className="flex gap-2">
          <Link href="/admin/posts/new" className="btn-primary text-sm">+ New Post</Link>
          <Link href="/admin/ai-agent/run" className="btn-secondary text-sm flex items-center gap-1.5"><Bot className="h-4 w-4" />Run AI Agent</Link>
        </div>
      </div>

      {/* Stat Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map((s) => (
          <Link key={s.label} href={s.href} className="card p-5 hover:shadow-md transition-all group">
            <div className="flex items-start justify-between mb-3">
              <div className={`h-10 w-10 rounded-xl ${s.bg} flex items-center justify-center`}>
                <s.icon className={`h-5 w-5 ${s.color}`} />
              </div>
              <ArrowUpRight className="h-4 w-4 text-muted-foreground group-hover:text-brand-600 transition-colors" />
            </div>
            <p className="text-2xl font-bold">{s.value}</p>
            <p className="text-sm text-muted-foreground mt-0.5">{s.label}</p>
          </Link>
        ))}
      </div>

      {/* Charts */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Views Chart */}
        <div className="lg:col-span-2 card p-5">
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold">Page Views (Last 30 Days)</h2>
            <span className="badge bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400 text-xs">+12.5%</span>
          </div>
          <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={stats?.viewsChart || []}>
              <defs>
                <linearGradient id="viewsGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#ea580c" stopOpacity={0.3} />
                  <stop offset="95%" stopColor="#ea580c" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} className="text-muted-foreground" />
              <YAxis tick={{ fontSize: 11 }} className="text-muted-foreground" />
              <Tooltip contentStyle={{ background: "hsl(var(--card))", border: "1px solid hsl(var(--border))", borderRadius: 8, fontSize: 12 }} />
              <Area type="monotone" dataKey="views" stroke="#ea580c" strokeWidth={2} fill="url(#viewsGrad)" />
            </AreaChart>
          </ResponsiveContainer>
        </div>

        {/* Category Distribution */}
        <div className="card p-5">
          <h2 className="font-semibold mb-4">Category Distribution</h2>
          <ResponsiveContainer width="100%" height={200}>
            <PieChart>
              <Pie data={stats?.categoryDistribution || []} cx="50%" cy="50%" innerRadius={50} outerRadius={80} dataKey="count" nameKey="name">
                {(stats?.categoryDistribution || []).map((_,i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
              </Pie>
              <Tooltip contentStyle={{ background: "hsl(var(--card))", border: "1px solid hsl(var(--border))", borderRadius: 8, fontSize: 12 }} />
            </PieChart>
          </ResponsiveContainer>
          <div className="space-y-1.5 mt-2">
            {(stats?.categoryDistribution || []).slice(0,5).map((c,i) => (
              <div key={c.name} className="flex items-center justify-between text-sm">
                <div className="flex items-center gap-2">
                  <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: COLORS[i % COLORS.length] }} />
                  <span className="text-muted-foreground truncate max-w-28">{c.name}</span>
                </div>
                <span className="font-medium">{c.count}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Recent Posts */}
      <div className="card">
        <div className="flex items-center justify-between p-5 border-b border-border">
          <h2 className="font-semibold">Recent Posts</h2>
          <Link href="/admin/posts" className="text-sm text-brand-600 hover:underline">View all</Link>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-border bg-muted/50">
              <th className="text-left p-3 font-medium text-muted-foreground">Title</th>
              <th className="text-left p-3 font-medium text-muted-foreground">Status</th>
              <th className="text-right p-3 font-medium text-muted-foreground">Views</th>
              <th className="text-right p-3 font-medium text-muted-foreground">Published</th>
              <th className="text-right p-3 font-medium text-muted-foreground">Actions</th>
            </tr></thead>
            <tbody>
              {(stats?.recentPosts || []).map((post) => (
                <tr key={post.id} className="border-b border-border/50 hover:bg-muted/30 transition-colors">
                  <td className="p-3 max-w-xs">
                    <span className="font-medium line-clamp-1">{post.title}</span>
                  </td>
                  <td className="p-3">
                    <StatusBadge status={post.status} />
                  </td>
                  <td className="p-3 text-right text-muted-foreground">{formatNumber(post.viewCount)}</td>
                  <td className="p-3 text-right text-muted-foreground">
                    {post.publishedAt ? formatRelativeDate(post.publishedAt) : "—"}
                  </td>
                  <td className="p-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <Link href={`/admin/posts/${post.id}/edit`} className="btn-secondary text-xs h-7 px-2">Edit</Link>
                      <Link href={`/article/${post.slug}`} target="_blank" className="btn-secondary text-xs h-7 px-2">View</Link>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const map: Record<string,{label:string,class:string,icon:React.ComponentType<{className?:string}>}> = {
    PUBLISHED: { label: "Published", class: "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400", icon: CheckCircle },
    DRAFT: { label: "Draft", class: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400", icon: Clock },
    REVIEW: { label: "Review", class: "bg-yellow-100 text-yellow-700 dark:bg-yellow-950 dark:text-yellow-400", icon: AlertCircle },
    SCHEDULED: { label: "Scheduled", class: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-400", icon: Clock },
    ARCHIVED: { label: "Archived", class: "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400", icon: XCircle },
  };
  const s = map[status] || map.DRAFT;
  return <span className={`badge text-xs ${s.class} flex items-center gap-1 w-fit`}><s.icon className="h-3 w-3" />{s.label}</span>;
}
