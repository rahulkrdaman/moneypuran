"use client";
import { useState, useEffect } from "react";
import { Bot, Play, RefreshCw, CheckCircle, XCircle, Clock, AlertCircle, Rss, Zap } from "lucide-react";
import { formatRelativeDate } from "@/lib/utils";

interface AILog { id:string;originalTitle:string;sourceUrl:string;status:string;qualityScore:number|null;tokensUsed:number|null;processingTimeMs:number|null;createdAt:string;rssSource:{name:string}|null;post:{slug:string}|null }

const STATUS_ICON:Record<string,React.ReactNode> = {
  COMPLETED: <CheckCircle className="h-4 w-4 text-green-500" />,
  FAILED: <XCircle className="h-4 w-4 text-red-500" />,
  PROCESSING: <RefreshCw className="h-4 w-4 text-blue-500 animate-spin" />,
  PENDING: <Clock className="h-4 w-4 text-yellow-500" />,
  HUMAN_REVIEW: <AlertCircle className="h-4 w-4 text-orange-500" />,
};

export default function AIAgentPage() {
  const [logs, setLogs] = useState<AILog[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState("");
  const [running, setRunning] = useState(false);
  const [loading, setLoading] = useState(true);
  const [rssSources, setRssSources] = useState<{id:string;name:string;isActive:boolean;lastFetchedAt:string|null;postsGenerated:number}[]>([]);

  useEffect(() => {
    fetchLogs();
    fetch("/api/rss-sources").then(r=>r.json()).then(d=>setRssSources(d.data||[]));
  }, [page, statusFilter]);

  async function fetchLogs() {
    setLoading(true);
    const params = new URLSearchParams({ page:String(page) });
    if (statusFilter) params.set("status", statusFilter);
    const res = await fetch(`/api/ai-agent/logs?${params}`);
    const data = await res.json();
    if (data.success) { setLogs(data.data||[]); setTotal(data.meta?.total||0); }
    setLoading(false);
  }

  async function runAgent() {
    setRunning(true);
    const res = await fetch("/api/ai-agent/run", { method:"POST" });
    const data = await res.json();
    alert(data.message || (data.success?"Agent started!":"Failed"));
    setTimeout(fetchLogs, 3000);
    setRunning(false);
  }

  const stats = { total:total, completed:logs.filter(l=>l.status==="COMPLETED").length, failed:logs.filter(l=>l.status==="FAILED").length, review:logs.filter(l=>l.status==="HUMAN_REVIEW").length };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3"><Bot className="h-7 w-7 text-purple-500" /><div><h1 className="text-2xl font-heading font-bold">AI Agent</h1><p className="text-muted-foreground text-sm">Automated content generation from RSS feeds</p></div></div>
        <button onClick={runAgent} disabled={running} className="btn-primary text-sm flex items-center gap-2">
          {running ? <><RefreshCw className="h-4 w-4 animate-spin" />Running...</> : <><Play className="h-4 w-4" />Run Agent Now</>}
        </button>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{label:"Total Processed",value:total,color:"text-blue-500",bg:"bg-blue-50 dark:bg-blue-950/30"},{label:"Completed",value:stats.completed,color:"text-green-500",bg:"bg-green-50 dark:bg-green-950/30"},{label:"Failed",value:stats.failed,color:"text-red-500",bg:"bg-red-50 dark:bg-red-950/30"},{label:"Needs Review",value:stats.review,color:"text-orange-500",bg:"bg-orange-50 dark:bg-orange-950/30"}].map(s=>(
          <div key={s.label} className="card p-4 flex items-center gap-3">
            <div className={`h-10 w-10 rounded-xl ${s.bg} flex items-center justify-center`}><Zap className={`h-5 w-5 ${s.color}`} /></div>
            <div><p className="text-xl font-bold">{s.value}</p><p className="text-xs text-muted-foreground">{s.label}</p></div>
          </div>
        ))}
      </div>

      {/* RSS Sources */}
      <div className="card p-5">
        <div className="flex items-center gap-2 mb-4"><Rss className="h-5 w-5 text-brand-600" /><h2 className="font-semibold">RSS Sources</h2></div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {rssSources.map(src=>(
            <div key={src.id} className="p-3 border border-border rounded-lg">
              <div className="flex items-center justify-between mb-1">
                <p className="text-sm font-medium">{src.name}</p>
                <span className={`badge text-xs ${src.isActive?"bg-green-100 text-green-700":"bg-gray-100 text-gray-500"}`}>{src.isActive?"Active":"Off"}</span>
              </div>
              <p className="text-xs text-muted-foreground">{src.postsGenerated} published · {src.lastFetchedAt ? `Last: ${formatRelativeDate(src.lastFetchedAt)}` : "Never fetched"}</p>
            </div>
          ))}
        </div>
      </div>

      {/* Logs */}
      <div className="card overflow-hidden">
        <div className="flex items-center justify-between p-4 border-b border-border">
          <h2 className="font-semibold">Processing Logs</h2>
          <div className="flex items-center gap-2">
            <select value={statusFilter} onChange={e=>{setStatusFilter(e.target.value);setPage(1);}} className="input h-8 text-xs w-36">
              <option value="">All Status</option>
              {["COMPLETED","FAILED","PENDING","PROCESSING","HUMAN_REVIEW"].map(s=><option key={s} value={s}>{s}</option>)}
            </select>
            <button onClick={fetchLogs} className="btn-secondary h-8 w-8 p-0"><RefreshCw className="h-3.5 w-3.5" /></button>
          </div>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-border bg-muted/50 text-left">
              <th className="p-3 font-medium text-muted-foreground">Status</th>
              <th className="p-3 font-medium text-muted-foreground">Original Title</th>
              <th className="p-3 font-medium text-muted-foreground">Source</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Quality</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Tokens</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Time</th>
              <th className="p-3 font-medium text-muted-foreground text-right">Created</th>
            </tr></thead>
            <tbody>
              {loading ? Array.from({length:5}).map((_,i)=>(<tr key={i} className="border-b border-border/50"><td colSpan={7} className="p-3"><div className="h-4 skeleton rounded w-full" /></td></tr>))
              : logs.map(log=>(
                <tr key={log.id} className="border-b border-border/50 hover:bg-muted/30 transition-colors">
                  <td className="p-3"><div className="flex items-center gap-2">{STATUS_ICON[log.status]||<Clock className="h-4 w-4" />}<span className="text-xs">{log.status}</span></div></td>
                  <td className="p-3 max-w-xs"><p className="text-xs line-clamp-2">{log.originalTitle}</p>{log.post && <a href={`/article/${log.post.slug}`} target="_blank" className="text-xs text-brand-600 hover:underline">View post →</a>}</td>
                  <td className="p-3 text-xs text-muted-foreground">{log.rssSource?.name||"—"}</td>
                  <td className="p-3 text-right"><span className={`badge text-xs ${log.qualityScore&&log.qualityScore>=0.7?"bg-green-100 text-green-700":"bg-red-100 text-red-700"}`}>{log.qualityScore?`${(log.qualityScore*100).toFixed(0)}%`:"—"}</span></td>
                  <td className="p-3 text-right text-xs text-muted-foreground">{log.tokensUsed||"—"}</td>
                  <td className="p-3 text-right text-xs text-muted-foreground">{log.processingTimeMs?`${(log.processingTimeMs/1000).toFixed(1)}s`:"—"}</td>
                  <td className="p-3 text-right text-xs text-muted-foreground">{formatRelativeDate(log.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {total > 20 && (
          <div className="flex items-center justify-between p-4 border-t border-border">
            <p className="text-xs text-muted-foreground">Page {page} · {total} total</p>
            <div className="flex gap-2"><button disabled={page===1} onClick={()=>setPage(p=>p-1)} className="btn-secondary text-xs h-7 px-3 disabled:opacity-50">← Prev</button><button disabled={page*20>=total} onClick={()=>setPage(p=>p+1)} className="btn-secondary text-xs h-7 px-3 disabled:opacity-50">Next →</button></div>
          </div>
        )}
      </div>
    </div>
  );
}