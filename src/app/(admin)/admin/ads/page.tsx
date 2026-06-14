"use client";
import { useState, useEffect } from "react";
import { Plus, X, Edit2, Trash2, Eye, MousePointerClick, ToggleLeft, ToggleRight, Megaphone } from "lucide-react";

interface Ad {
  id: string; name: string; type: string; placement: string;
  imageUrl: string | null; linkUrl: string | null;
  htmlCode: string | null; adsenseSlot: string | null; altText: string | null;
  width: number | null; height: number | null;
  isActive: boolean; startDate: string | null; endDate: string | null;
  impressions: number; clicks: number; priority: number;
}

const PLACEMENTS = ["HEADER","SIDEBAR","IN_ARTICLE","FOOTER","POPUP","BETWEEN_POSTS"];
const TYPES = ["IMAGE","ADSENSE","CUSTOM_HTML","AFFILIATE"];

const emptyForm = {
  name:"", type:"IMAGE", placement:"SIDEBAR",
  imageUrl:"", linkUrl:"", htmlCode:"", adsenseSlot:"", altText:"",
  width:"", height:"", isActive:true, startDate:"", endDate:"", priority:0,
};

export default function AdsPage() {
  const [ads, setAds] = useState<Ad[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [form, setForm] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);
  const [filterPlacement, setFilterPlacement] = useState("ALL");

  const load = () => {
    fetch("/api/ads").then(r => r.json()).then(d => { setAds(d.data || []); setLoading(false); });
  };
  useEffect(load, []);

  const filtered = ads.filter(a => filterPlacement === "ALL" || a.placement === filterPlacement);
  const totalImpressions = ads.reduce((s, a) => s + a.impressions, 0);
  const totalClicks = ads.reduce((s, a) => s + a.clicks, 0);
  const ctr = totalImpressions ? ((totalClicks / totalImpressions) * 100).toFixed(2) : "0.00";

  async function handleSave() {
    if (!form.name.trim()) return;
    setSaving(true);
    const payload = {
      ...form,
      width: form.width ? parseInt(String(form.width)) : null,
      height: form.height ? parseInt(String(form.height)) : null,
      startDate: form.startDate || null,
      endDate: form.endDate || null,
      imageUrl: form.imageUrl || null,
      linkUrl: form.linkUrl || null,
      htmlCode: form.htmlCode || null,
      adsenseSlot: form.adsenseSlot || null,
      altText: form.altText || null,
    };
    const url = editId ? `/api/ads/${editId}` : "/api/ads";
    const method = editId ? "PUT" : "POST";
    const res = await fetch(url, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    const d = await res.json();
    if (d.success) {
      if (editId) setAds(list => list.map(a => a.id === editId ? { ...a, ...d.data } : a));
      else setAds(list => [...list, d.data]);
      setShowForm(false); setEditId(null); setForm({ ...emptyForm });
    }
    setSaving(false);
  }

  function startEdit(ad: Ad) {
    setEditId(ad.id);
    setForm({
      name: ad.name, type: ad.type, placement: ad.placement,
      imageUrl: ad.imageUrl || "", linkUrl: ad.linkUrl || "",
      htmlCode: ad.htmlCode || "", adsenseSlot: ad.adsenseSlot || "",
      altText: ad.altText || "",
      width: ad.width ? String(ad.width) : "",
      height: ad.height ? String(ad.height) : "",
      isActive: ad.isActive, priority: ad.priority,
      startDate: ad.startDate ? ad.startDate.split("T")[0] : "",
      endDate: ad.endDate ? ad.endDate.split("T")[0] : "",
    });
    setShowForm(true);
  }

  async function toggleActive(id: string, current: boolean) {
    await fetch(`/api/ads/${id}`, { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ isActive: !current }) });
    setAds(list => list.map(a => a.id === id ? { ...a, isActive: !current } : a));
  }

  async function handleDelete(id: string) {
    if (!confirm("Delete this advertisement?")) return;
    await fetch(`/api/ads/${id}`, { method: "DELETE" });
    setAds(list => list.filter(a => a.id !== id));
  }

  const isHtmlBased = form.type === "CUSTOM_HTML" || form.type === "AFFILIATE";
  const isImageBased = form.type === "IMAGE";
  const isAdSense = form.type === "ADSENSE";

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-heading font-bold">Advertisements</h1>
          <p className="text-muted-foreground text-sm">{ads.filter(a => a.isActive).length} active · {ads.length} total</p>
        </div>
        <button onClick={() => { setShowForm(!showForm); setEditId(null); setForm({ ...emptyForm }); }}
          className="btn-primary text-sm flex items-center gap-1.5">
          <Plus className="h-4 w-4" /> New Ad
        </button>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {[
          { label: "Total Ads",    value: ads.length,                 icon: Megaphone,         color: "text-brand-600"  },
          { label: "Impressions",  value: totalImpressions.toLocaleString(), icon: Eye,        color: "text-blue-600"   },
          { label: "Clicks",       value: totalClicks.toLocaleString(),       icon: MousePointerClick, color: "text-green-600"  },
          { label: "CTR",          value: ctr + "%",                  icon: ToggleRight,       color: "text-orange-600" },
        ].map(({ label, value, icon: Icon, color }) => (
          <div key={label} className="card p-4 flex items-center gap-3">
            <div className={`p-2 rounded-lg bg-muted ${color}`}><Icon className="h-5 w-5" /></div>
            <div><p className="text-xl font-bold">{value}</p><p className="text-xs text-muted-foreground">{label}</p></div>
          </div>
        ))}
      </div>

      {/* Form */}
      {showForm && (
        <div className="card p-5 space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="font-semibold">{editId ? "Edit Ad" : "Create Ad"}</h3>
            <button onClick={() => { setShowForm(false); setEditId(null); }} className="p-1 rounded hover:bg-muted"><X className="h-4 w-4" /></button>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label className="label">Ad Name *</label>
              <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="input" placeholder="e.g. Sidebar Banner Q1" /></div>
            <div><label className="label">Type</label>
              <select value={form.type} onChange={e => setForm(f => ({ ...f, type: e.target.value }))} className="input">
                {TYPES.map(t => <option key={t}>{t}</option>)}
              </select></div>
            <div><label className="label">Placement</label>
              <select value={form.placement} onChange={e => setForm(f => ({ ...f, placement: e.target.value }))} className="input">
                {PLACEMENTS.map(p => <option key={p}>{p}</option>)}
              </select></div>
            <div><label className="label">Priority (higher = shown first)</label>
              <input type="number" value={form.priority} onChange={e => setForm(f => ({ ...f, priority: parseInt(e.target.value) || 0 }))} className="input" /></div>

            {/* Image Ad fields */}
            {(isImageBased || form.type === "AFFILIATE") && <>
              <div className="sm:col-span-2"><label className="label">Image URL</label>
                <input value={form.imageUrl} onChange={e => setForm(f => ({ ...f, imageUrl: e.target.value }))} className="input" placeholder="https://..." /></div>
              <div className="sm:col-span-2"><label className="label">Link URL</label>
                <input value={form.linkUrl} onChange={e => setForm(f => ({ ...f, linkUrl: e.target.value }))} className="input" placeholder="https://..." /></div>
              <div><label className="label">Alt Text</label>
                <input value={form.altText} onChange={e => setForm(f => ({ ...f, altText: e.target.value }))} className="input" placeholder="Descriptive text" /></div>
            </>}

            {/* AdSense fields */}
            {isAdSense && <>
              <div><label className="label">AdSense Slot ID</label>
                <input value={form.adsenseSlot} onChange={e => setForm(f => ({ ...f, adsenseSlot: e.target.value }))} className="input" placeholder="1234567890" /></div>
            </>}

            {/* HTML/Affiliate embed */}
            {isHtmlBased && <>
              <div className="sm:col-span-2"><label className="label">HTML / Embed Code</label>
                <textarea value={form.htmlCode} onChange={e => setForm(f => ({ ...f, htmlCode: e.target.value }))} className="input resize-none font-mono text-xs" rows={5} placeholder="<script>...</script>" /></div>
            </>}

            {/* Dimensions (all types) */}
            <div><label className="label">Width (px, optional)</label>
              <input type="number" value={form.width} onChange={e => setForm(f => ({ ...f, width: e.target.value }))} className="input" placeholder="300" /></div>
            <div><label className="label">Height (px, optional)</label>
              <input type="number" value={form.height} onChange={e => setForm(f => ({ ...f, height: e.target.value }))} className="input" placeholder="250" /></div>

            {/* Dates & Status */}
            <div><label className="label">Start Date</label>
              <input type="date" value={form.startDate} onChange={e => setForm(f => ({ ...f, startDate: e.target.value }))} className="input" /></div>
            <div><label className="label">End Date</label>
              <input type="date" value={form.endDate} onChange={e => setForm(f => ({ ...f, endDate: e.target.value }))} className="input" /></div>
            <div><label className="label">Status</label>
              <button type="button" onClick={() => setForm(f => ({ ...f, isActive: !f.isActive }))}
                className={`flex items-center gap-2 px-3 py-2 rounded-lg border text-sm transition-colors ${form.isActive ? "bg-green-50 border-green-200 text-green-700 dark:bg-green-950 dark:border-green-800 dark:text-green-400" : "border-input text-muted-foreground"}`}>
                {form.isActive ? <ToggleRight className="h-4 w-4" /> : <ToggleLeft className="h-4 w-4" />}
                {form.isActive ? "Active" : "Inactive"}
              </button></div>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={saving || !form.name} className="btn-primary text-sm">{saving ? "Saving..." : editId ? "Update" : "Create Ad"}</button>
            <button onClick={() => { setShowForm(false); setEditId(null); }} className="btn-secondary text-sm">Cancel</button>
          </div>
        </div>
      )}

      {/* Filter */}
      <div className="flex gap-2 flex-wrap">
        {["ALL", ...PLACEMENTS].map(p => (
          <button key={p} onClick={() => setFilterPlacement(p)}
            className={`px-3 py-1 rounded-full text-xs font-medium transition-colors border ${filterPlacement === p ? "bg-brand-600 text-white border-brand-600" : "border-border text-muted-foreground hover:bg-muted"}`}>
            {p}
          </button>
        ))}
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-border bg-muted/50 text-left">
              <th className="p-3 font-medium text-muted-foreground">Name</th>
              <th className="p-3 font-medium text-muted-foreground hidden sm:table-cell">Type</th>
              <th className="p-3 font-medium text-muted-foreground hidden md:table-cell">Placement</th>
              <th className="p-3 font-medium text-muted-foreground hidden lg:table-cell">Impressions</th>
              <th className="p-3 font-medium text-muted-foreground hidden lg:table-cell">Clicks</th>
              <th className="p-3 font-medium text-muted-foreground">Status</th>
              <th className="p-3 font-medium text-muted-foreground">Actions</th>
            </tr></thead>
            <tbody>
              {loading ? Array.from({length:5}).map((_,i) => (
                <tr key={i} className="border-b border-border">
                  {Array.from({length:7}).map((_,j) => <td key={j} className="p-3"><div className="h-4 bg-muted rounded animate-pulse" /></td>)}
                </tr>
              )) : filtered.length === 0 ? (
                <tr><td colSpan={7} className="p-8 text-center text-muted-foreground">No ads found.</td></tr>
              ) : filtered.map(ad => (
                <tr key={ad.id} className="border-b border-border hover:bg-muted/30 transition-colors">
                  <td className="p-3">
                    <div className="font-medium">{ad.name}</div>
                    {ad.linkUrl && <div className="text-xs text-muted-foreground truncate max-w-xs">{ad.linkUrl}</div>}
                  </td>
                  <td className="p-3 hidden sm:table-cell"><span className="badge bg-muted text-muted-foreground">{ad.type}</span></td>
                  <td className="p-3 hidden md:table-cell"><span className="badge bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300">{ad.placement}</span></td>
                  <td className="p-3 hidden lg:table-cell text-muted-foreground">{ad.impressions.toLocaleString()}</td>
                  <td className="p-3 hidden lg:table-cell text-muted-foreground">{ad.clicks.toLocaleString()}</td>
                  <td className="p-3">
                    <button onClick={() => toggleActive(ad.id, ad.isActive)}
                      className={`badge cursor-pointer ${ad.isActive ? "bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300" : "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"}`}>
                      {ad.isActive ? "Active" : "Inactive"}
                    </button>
                  </td>
                  <td className="p-3">
                    <div className="flex gap-1">
                      <button onClick={() => startEdit(ad)} className="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground"><Edit2 className="h-3.5 w-3.5" /></button>
                      <button onClick={() => handleDelete(ad.id)} className="p-1.5 rounded hover:bg-red-50 dark:hover:bg-red-950 text-muted-foreground hover:text-red-600"><Trash2 className="h-3.5 w-3.5" /></button>
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
