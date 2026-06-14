"use client";
import { useState, useEffect } from "react";
import { Plus, X, Edit2, Trash2, RefreshCw, CheckCircle, XCircle, Rss, Play, ToggleLeft, ToggleRight, Globe } from "lucide-react";

interface RssSource {
  id: string; name: string; url: string; category: string | null;
  isActive: boolean; fetchInterval: number; lastFetchedAt: string | null;
  lastError: string | null; postsGenerated: number;
}

const emptyForm = { name: "", url: "", category: "", fetchInterval: 60, isActive: true };

const INTERVALS = [
  { label: "15 min", value: 15 }, { label: "30 min", value: 30 }, { label: "1 hour", value: 60 },
  { label: "2 hours", value: 120 }, { label: "6 hours", value: 360 }, { label: "12 hours", value: 720 },
  { label: "24 hours", value: 1440 },
];

export default function RssSourcesPage() {
  const [sources, setSources] = useState<RssSource[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [form, setForm] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [running, setRunning] = useState(false);

  const load = () => {
    fetch("/api/rss-sources").then(r => r.json()).then(d => { setSources(d.data || []); setLoading(false); });
  };
  useEffect(load, []);

  async function handleSave() {
    if (!form.name || !form.url) return;
    setSaving(true);
    const payload = { ...form, category: form.category || null };
    const url = editId ? `/api/rss-sources/${editId}` : "/api/rss-sources";
    const method = editId ? "PUT" : "POST";
    const res = await fetch(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    const d = await res.json();
    if (d.success) {
      if (editId) setSources(s => s.map(src => src.id === editId ? { ...src, ...d.data } : src));
      else setSources(s => [...s, d.data]);
      setShowForm(false); setEditId(null); setForm({ ...emptyForm });
    }
    setSaving(false);
  }

  async function testFeed(url: string, id?: string) {
    setTesting(id || "new"); setTestResult(null);
    try {
      const res = await fetch("/api/rss-sources/test", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ url }) });
      const d = await res.json();
      setTestResult({ ok: d.success, message: d.success ? `Valid feed – found ${d.count || 0} items` : d.error || "Invalid feed" });
    } catch { setTestResult({ ok: false, message: "Network error" }); }
    setTesting(null);
  }

  async function toggleActive(id: string, current: boolean) {
    await fetch(`/api/rss-sources/${id}`, { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ isActive: !current }) });
    setSources(s => s.map(src => src.id === id ? { ...src, isActive: !current } : src));
  }

  async function handleDelete(id: string) {
    if (!confirm("Remove this RSS source?")) return;
    await fetch(`/api/rss-sources/${id}`, { method: "DELETE" });
    setSources(s => s.filter(src => src.id !== id));
  }

  async function runAgent() {
    setRunning(true);
    await fetch("/api/ai-agent/run", { method: "POST" });
    setRunning(false);
    setTimeout(load, 3000);
  }

  function startEdit(src: RssSource) {
    setEditId(src.id);
    setForm({ name: src.name, url: src.url, category: src.category || "", fetchInterval: src.fetchInterval, isActive: src.isActive });
    setShowForm(true); setTestResult(null);
  }

  const active = sources.filter(s => s.isActive).length;
  const total = sources.reduce((acc, s) => acc + s.postsGenerated, 0);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-heading font-bold">RSS Sources</h1>
          <p className="text-muted-foreground text-sm">{active} active of {sources.length} · {total} posts generated</p>
        </div>
        <div className="flex gap-2">
          <button onClick={runAgent} disabled={running} className="btn-secondary text-sm flex items-center gap-1.5">
            {running ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Play className="h-4 w-4" />}
            {running ? "Running..." : "Run AI Agent"}
          </button>
          <button onClick={() => { setShowForm(!showForm); setEditId(null); setForm({ ...emptyForm }); setTestResult(null); }}
            className="btn-primary text-sm flex items-center gap-1.5">
            <Plus className="h-4 w-4" /> Add Source
          </button>
        </div>
      </div>

      {/* Form */}
      {showForm && (
        <div className="card p-5 space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="font-semibold">{editId ? "Edit Source" : "Add RSS Source"}</h3>
            <button onClick={() => { setShowForm(false); setEditId(null); setTestResult(null); }} className="p-1 rounded hover:bg-muted"><X className="h-4 w-4" /></button>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label className="label">Source Name *</label>
              <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="input" placeholder="e.g. Economic Times Markets" /></div>
            <div><label className="label">Category (optional)</label>
              <input value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} className="input" placeholder="e.g. markets" /></div>
            <div className="sm:col-span-2">
              <label className="label">RSS Feed URL *</label>
              <div className="flex gap-2">
                <input value={form.url} onChange={e => setForm(f => ({ ...f, url: e.target.value }))} className="input flex-1" placeholder="https://economictimes.com/.../rss.xml" />
                <button onClick={() => testFeed(form.url)} disabled={!form.url || testing === "new"}
                  className="btn-secondary text-sm flex-shrink-0 flex items-center gap-1">
                  {testing === "new" ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Globe className="h-4 w-4" />} Test
                </button>
              </div>
              {testResult && (
                <div className={`mt-2 flex items-center gap-2 text-sm ${testResult.ok ? "text-green-600" : "text-red-600"}`}>
                  {testResult.ok ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
                  {testResult.message}
                </div>
              )}
            </div>
            <div><label className="label">Fetch Interval</label>
              <select value={form.fetchInterval} onChange={e => setForm(f => ({ ...f, fetchInterval: +e.target.value }))} className="input">
                {INTERVALS.map(i => <option key={i.value} value={i.value}>{i.label}</option>)}
              </select></div>
            <div><label className="label">Status</label>
              <button type="button" onClick={() => setForm(f => ({ ...f, isActive: !f.isActive }))}
                className={`flex items-center gap-2 px-3 py-2 rounded-lg border text-sm transition-colors w-full ${form.isActive ? "bg-green-50 border-green-200 text-green-700 dark:bg-green-950 dark:border-green-800 dark:text-green-400" : "border-input text-muted-foreground"}`}>
                {form.isActive ? <ToggleRight className="h-4 w-4" /> : <ToggleLeft className="h-4 w-4" />}
                {form.isActive ? "Active" : "Inactive"}
              </button></div>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={saving || !form.name || !form.url} className="btn-primary text-sm">{saving ? "Saving..." : editId ? "Update" : "Add Source"}</button>
            <button onClick={() => { setShowForm(false); setEditId(null); setTestResult(null); }} className="btn-secondary text-sm">Cancel</button>
          </div>
        </div>
      )}

      {/* List */}
      {loading ? (
        <div className="space-y-3">{Array.from({length:4}).map((_,i) => <div key={i} className="h-24 rounded-xl bg-muted animate-pulse" />)}</div>
      ) : sources.length === 0 ? (
        <div className="card p-12 text-center text-muted-foreground">
          <Rss className="h-8 w-8 mx-auto mb-2 opacity-30" />
          <p className="text-sm">No RSS sources yet. Add your first feed.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {sources.map(src => (
            <div key={src.id} className="card p-4">
              <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                <div className="flex items-start gap-3 min-w-0">
                  <div className={`mt-0.5 p-2 rounded-lg flex-shrink-0 ${src.isActive ? "bg-green-50 dark:bg-green-950 text-green-600" : "bg-muted text-muted-foreground"}`}>
                    <Rss className="h-4 w-4" />
                  </div>
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium">{src.name}</span>
                      {src.category && <span className="badge bg-muted text-muted-foreground text-xs">{src.category}</span>}
                      <span className={`badge text-xs ${src.isActive ? "bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300" : "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"}`}>
                        {src.isActive ? "Active" : "Paused"}
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground truncate mt-0.5">{src.url}</p>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-muted-foreground">
                      <span>Every {INTERVALS.find(i => i.value === src.fetchInterval)?.label || src.fetchInterval + "m"}</span>
                      <span>{src.postsGenerated} posts generated</span>
                      {src.lastFetchedAt && <span>Last: {new Date(src.lastFetchedAt).toLocaleString("en-IN")}</span>}
                      {src.lastError && <span className="text-red-500 flex items-center gap-1"><XCircle className="h-3 w-3" />Error</span>}
                    </div>
                    {src.lastError && (
                      <div className="mt-2 px-2.5 py-1.5 rounded bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 text-xs text-red-700 dark:text-red-300">
                        {src.lastError}
                      </div>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-1.5 flex-shrink-0">
                  <button onClick={() => testFeed(src.url, src.id)} disabled={testing === src.id}
                    className="p-1.5 rounded hover:bg-muted text-muted-foreground" title="Test feed">
                    {testing === src.id ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : <Globe className="h-3.5 w-3.5" />}
                  </button>
                  <button onClick={() => toggleActive(src.id, src.isActive)}
                    className="p-1.5 rounded hover:bg-muted text-muted-foreground" title="Toggle active">
                    {src.isActive ? <ToggleRight className="h-3.5 w-3.5 text-green-600" /> : <ToggleLeft className="h-3.5 w-3.5" />}
                  </button>
                  <button onClick={() => startEdit(src)} className="p-1.5 rounded hover:bg-muted text-muted-foreground"><Edit2 className="h-3.5 w-3.5" /></button>
                  <button onClick={() => handleDelete(src.id)} className="p-1.5 rounded hover:bg-red-50 dark:hover:bg-red-950 text-muted-foreground hover:text-red-600"><Trash2 className="h-3.5 w-3.5" /></button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
