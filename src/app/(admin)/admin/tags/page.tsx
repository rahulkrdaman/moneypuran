"use client";
import { useState, useEffect } from "react";
import { Plus, Edit2, Trash2, Tag, X, Check } from "lucide-react";

interface TagItem { id: string; name: string; slug: string; color: string | null; _count: { posts: number } }

const PRESET_COLORS = ["#ea580c","#2563eb","#16a34a","#dc2626","#7c3aed","#db2777","#d97706","#0891b2","#059669","#64748b"];

export default function TagsPage() {
  const [tags, setTags] = useState<TagItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [form, setForm] = useState({ name: "", color: "#ea580c" });
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  const load = () => {
    fetch("/api/tags").then(r => r.json()).then(d => { setTags(d.data || []); setLoading(false); });
  };
  useEffect(load, []);

  const filtered = tags.filter(t => t.name.toLowerCase().includes(search.toLowerCase()));

  async function handleSave() {
    if (!form.name.trim()) return;
    setSaving(true);
    if (editId) {
      const res = await fetch(`/api/tags/${editId}`, { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(form) });
      const d = await res.json();
      if (d.success) setTags(ts => ts.map(t => t.id === editId ? { ...t, ...d.data } : t));
      setEditId(null);
    } else {
      const res = await fetch("/api/tags", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(form) });
      const d = await res.json();
      if (d.success) setTags(ts => [...ts, d.data]);
    }
    setForm({ name: "", color: "#ea580c" }); setShowForm(false); setSaving(false);
  }

  function startEdit(t: TagItem) {
    setEditId(t.id); setForm({ name: t.name, color: t.color || "#ea580c" }); setShowForm(true);
  }

  async function handleDelete(id: string) {
    if (!confirm("Delete this tag? It will be removed from all posts.")) return;
    setDeleting(id);
    await fetch(`/api/tags/${id}`, { method: "DELETE" });
    setTags(ts => ts.filter(t => t.id !== id));
    setDeleting(null);
  }

  const totalPosts = tags.reduce((s, t) => s + t._count.posts, 0);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-heading font-bold">Tags</h1>
          <p className="text-muted-foreground text-sm">{tags.length} tags · {totalPosts} total posts tagged</p>
        </div>
        <button onClick={() => { setShowForm(!showForm); setEditId(null); setForm({ name: "", color: "#ea580c" }); }}
          className="btn-primary text-sm flex items-center gap-1.5">
          <Plus className="h-4 w-4" /> New Tag
        </button>
      </div>

      {/* Create/Edit Form */}
      {showForm && (
        <div className="card p-5">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-semibold">{editId ? "Edit Tag" : "Create Tag"}</h3>
            <button onClick={() => { setShowForm(false); setEditId(null); }} className="p-1 rounded hover:bg-muted"><X className="h-4 w-4" /></button>
          </div>
          <div className="space-y-4">
            <div>
              <label className="label">Tag Name *</label>
              <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                onKeyDown={e => e.key === "Enter" && handleSave()}
                className="input" placeholder="e.g. Stock Market, IPO, Budget 2025" />
            </div>
            <div>
              <label className="label">Color</label>
              <div className="flex items-center gap-3 flex-wrap">
                {PRESET_COLORS.map(c => (
                  <button key={c} onClick={() => setForm(f => ({ ...f, color: c }))}
                    className="h-7 w-7 rounded-full border-2 transition-all hover:scale-110 flex items-center justify-center"
                    style={{ backgroundColor: c, borderColor: form.color === c ? "#000" : "transparent" }}>
                    {form.color === c && <Check className="h-3.5 w-3.5 text-white" />}
                  </button>
                ))}
                <input type="color" value={form.color}
                  onChange={e => setForm(f => ({ ...f, color: e.target.value }))}
                  className="h-7 w-10 cursor-pointer rounded border border-input" title="Custom color" />
                <span className="text-sm text-muted-foreground font-mono">{form.color}</span>
              </div>
            </div>
            <div className="flex items-center gap-2">
              {form.name && (
                <span className="badge text-white text-sm px-3 py-1" style={{ backgroundColor: form.color }}>{form.name}</span>
              )}
            </div>
            <div className="flex gap-2">
              <button onClick={handleSave} disabled={saving || !form.name.trim()} className="btn-primary text-sm">
                {saving ? "Saving..." : editId ? "Update Tag" : "Create Tag"}
              </button>
              <button onClick={() => { setShowForm(false); setEditId(null); }} className="btn-secondary text-sm">Cancel</button>
            </div>
          </div>
        </div>
      )}

      {/* Search */}
      <div className="relative">
        <Tag className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <input value={search} onChange={e => setSearch(e.target.value)}
          className="input pl-9" placeholder="Search tags..." />
      </div>

      {/* Tags Grid */}
      {loading ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          {Array.from({length:15}).map((_,i) => <div key={i} className="h-16 rounded-xl bg-muted animate-pulse" />)}
        </div>
      ) : filtered.length === 0 ? (
        <div className="card p-12 text-center text-muted-foreground">
          <Tag className="h-8 w-8 mx-auto mb-2 opacity-30" />
          <p className="text-sm">{search ? "No tags match your search" : "No tags yet. Create your first tag."}</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
          {filtered.map(tag => (
            <div key={tag.id} className="card p-3 group relative overflow-hidden">
              <div className="absolute top-0 left-0 h-1 w-full" style={{ backgroundColor: tag.color || "#ea580c" }} />
              <div className="flex items-start justify-between gap-1 mt-1">
                <span className="font-medium text-sm leading-tight">{tag.name}</span>
                <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                  <button onClick={() => startEdit(tag)} className="p-1 rounded hover:bg-muted text-muted-foreground hover:text-foreground">
                    <Edit2 className="h-3 w-3" />
                  </button>
                  <button onClick={() => handleDelete(tag.id)} disabled={deleting === tag.id}
                    className="p-1 rounded hover:bg-red-50 dark:hover:bg-red-950 text-muted-foreground hover:text-red-600">
                    <Trash2 className="h-3 w-3" />
                  </button>
                </div>
              </div>
              <div className="flex items-center gap-1.5 mt-2">
                <div className="h-2 w-2 rounded-full flex-shrink-0" style={{ backgroundColor: tag.color || "#ea580c" }} />
                <span className="text-xs text-muted-foreground font-mono">{tag.slug}</span>
              </div>
              <div className="mt-2 text-xs text-muted-foreground">{tag._count.posts} post{tag._count.posts !== 1 ? "s" : ""}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
