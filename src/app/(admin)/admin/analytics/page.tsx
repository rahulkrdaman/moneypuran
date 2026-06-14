"use client";
import { useState, useEffect } from "react";
import { AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from "recharts";
import { TrendingUp, Eye, Users, FileText, ArrowUpRight, ArrowDownRight, Calendar } from "lucide-react";

interface DashboardStats {
  totalPosts: number; publishedPosts: number; totalUsers: number;
  totalViews: number; viewsToday: number; viewsThisMonth: number;
  newsletterSubscribers: number; aiLogsTotal: number;
  viewsByDay: { date: string; views: number }[];
  postsByCategory: { name: string; count: number }[];
  recentPosts: { id: string; title: string; views: number; status: string; createdAt: string }[];
}

const COLORS = ["#ea580c","#2563eb","#16a34a","#7c3aed","#db2777","#d97706","#0891b2"];
const RANGE_OPTIONS = [{ label: "7 days", days: 7 }, { label: "30 days", days: 30 }, { label: "90 days", days: 90 }];

function StatCard({ label, value, sub, icon: Icon, trend, color }: { label: string; value: string | number; sub?: string; icon: React.ElementType; trend?: number; color: string }) {
  return (
    <div className="card p-5">
      <div className="flex items-start justify-between">
        <div className={`p-2.5 rounded-xl ${color}`}><Icon className="h-5 w-5" /></div>
        {trend !== undefined && (
          <div className={`flex items-center gap-1 text-xs font-medium ${trend >= 0 ? "text-green-600" : "text-red-600"}`}>
            {trend >= 0 ? <ArrowUpRight className="h-3.5 w-3.5" /> : <ArrowDownRight className="h-3.5 w-3.5" />}
            {Math.abs(trend)}%
          </div>
        )}
      </div>
      <div className="mt-4">
        <p className="text-2xl font-bold tracking-tight">{typeof value === "number" ? value.toLocaleString() : value}</p>
        <p className="text-sm text-muted-foreground mt-0.5">{label}</p>
        {sub && <p className="text-xs text-muted-foreground mt-1">{sub}</p>}
      </div>
    </div>
  );
}

export default function AnalyticsPage() {
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [range, setRange] = useState(30);

  useEffect(() => {
    fetch("/api/analytics/dashboard").then(r => r.json()).then(d => {
      if (d.success) setStats(d.data);
      setLoading(false);
    });
  }, [range]);

  const chartData = stats?.viewsByDay.slice(-range) || [];
  const maxViews = chartData.length ? Math.max(...chartData.map(d => d.views)) : 1;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-heading font-bold">Analytics</h1>
          <p className="text-muted-foreground text-sm">Traffic and engagement overview</p>
        </div>
        <div className="flex gap-1 border border-border rounded-lg p-0.5 bg-muted/40">
          {RANGE_OPTIONS.map(opt => (
            <button key={opt.days} onClick={() => setRange(opt.days)}
              className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${range === opt.days ? "bg-card shadow text-foreground" : "text-muted-foreground hover:text-foreground"}`}>
              {opt.label}
            </button>
          ))}
        </div>
      </div>

      {/* Stat Cards */}
      {loading ? (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {Array.from({length:8}).map((_,i) => <div key={i} className="h-32 rounded-xl bg-muted animate-pulse" />)}
        </div>
      ) : stats && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard label="Total Views" value={stats.totalViews} sub={`${stats.viewsToday.toLocaleString()} today`} icon={Eye} trend={12} color="bg-blue-50 text-blue-600 dark:bg-blue-950" />
          <StatCard label="Monthly Views" value={stats.viewsThisMonth} sub="This month" icon={TrendingUp} trend={8} color="bg-green-50 text-green-600 dark:bg-green-950" />
          <StatCard label="Published Posts" value={stats.publishedPosts} sub={`${stats.totalPosts} total`} icon={FileText} color="bg-brand-50 text-brand-600 dark:bg-brand-950" />
          <StatCard label="Total Users" value={stats.totalUsers} sub="Registered accounts" icon={Users} color="bg-purple-50 text-purple-600 dark:bg-purple-950" />
        </div>
      )}

      {/* Traffic Chart */}
      <div className="card p-5">
        <h3 className="font-semibold mb-4">Daily Traffic — Last {range} Days</h3>
        {loading ? (
          <div className="h-64 bg-muted rounded-lg animate-pulse" />
        ) : (
          <ResponsiveContainer width="100%" height={260}>
            <AreaChart data={chartData} margin={{ top: 5, right: 5, left: 0, bottom: 0 }}>
              <defs>
                <linearGradient id="viewsGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#ea580c" stopOpacity={0.3} />
                  <stop offset="95%" stopColor="#ea580c" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} tickLine={false} axisLine={false}
                tickFormatter={v => new Date(v).toLocaleDateString("en-IN", { month: "short", day: "numeric" })} />
              <YAxis tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
              <Tooltip
                contentStyle={{ backgroundColor: "hsl(var(--card))", border: "1px solid hsl(var(--border))", borderRadius: "8px", fontSize: "12px" }}
                formatter={(v: number) => [v.toLocaleString(), "Views"]}
                labelFormatter={l => new Date(l).toLocaleDateString("en-IN", { weekday: "short", month: "short", day: "numeric" })} />
              <Area type="monotone" dataKey="views" stroke="#ea580c" strokeWidth={2} fill="url(#viewsGrad)" dot={false} activeDot={{ r: 5 }} />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </div>

      {/* Category + Top Posts */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Category Distribution */}
        <div className="card p-5">
          <h3 className="font-semibold mb-4">Posts by Category</h3>
          {loading ? <div className="h-48 bg-muted rounded animate-pulse" /> : (
            <div className="space-y-3">
              {(stats?.postsByCategory || []).map((cat, i) => {
                const total = stats!.totalPosts || 1;
                const pct = Math.round((cat.count / total) * 100);
                return (
                  <div key={cat.name}>
                    <div className="flex justify-between text-sm mb-1">
                      <span className="font-medium truncate">{cat.name}</span>
                      <span className="text-muted-foreground ml-2">{cat.count} ({pct}%)</span>
                    </div>
                    <div className="h-2 bg-muted rounded-full overflow-hidden">
                      <div className="h-full rounded-full transition-all duration-700"
                        style={{ width: `${pct}%`, backgroundColor: COLORS[i % COLORS.length] }} />
                    </div>
                  </div>
                );
              })}
              {(!stats?.postsByCategory?.length) && <p className="text-sm text-muted-foreground text-center py-8">No data yet</p>}
            </div>
          )}
        </div>

        {/* Top Posts */}
        <div className="card p-5">
          <h3 className="font-semibold mb-4">Top Posts by Views</h3>
          {loading ? <div className="h-48 bg-muted rounded animate-pulse" /> : (
            <div className="space-y-3">
              {(stats?.recentPosts || []).sort((a, b) => b.views - a.views).slice(0, 7).map((post, i) => (
                <div key={post.id} className="flex items-center gap-3">
                  <span className="text-lg font-bold text-muted-foreground/40 w-6 flex-shrink-0 text-right">{i + 1}</span>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{post.title}</p>
                    <p className="text-xs text-muted-foreground">{new Date(post.createdAt).toLocaleDateString("en-IN")}</p>
                  </div>
                  <div className="flex items-center gap-1 text-sm text-muted-foreground flex-shrink-0">
                    <Eye className="h-3.5 w-3.5" />{post.views.toLocaleString()}
                  </div>
                </div>
              ))}
              {!stats?.recentPosts?.length && <p className="text-sm text-muted-foreground text-center py-8">No posts yet</p>}
            </div>
          )}
        </div>
      </div>

      {/* Monthly Bar Chart */}
      <div className="card p-5">
        <h3 className="font-semibold mb-4">Weekly Traffic Comparison</h3>
        {loading ? <div className="h-48 bg-muted rounded animate-pulse" /> : (
          <ResponsiveContainer width="100%" height={200}>
            <BarChart data={chartData.filter((_, i) => i % 7 === 0).map((d, i, arr) => ({
              week: `W${i + 1}`,
              views: chartData.slice(i * 7, (i + 1) * 7).reduce((s, v) => s + v.views, 0)
            }))} margin={{ top: 5, right: 5, left: 0, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" vertical={false} />
              <XAxis dataKey="week" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
              <YAxis tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
              <Tooltip contentStyle={{ backgroundColor: "hsl(var(--card))", border: "1px solid hsl(var(--border))", borderRadius: "8px", fontSize: "12px" }} />
              <Bar dataKey="views" fill="#ea580c" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  );
}
