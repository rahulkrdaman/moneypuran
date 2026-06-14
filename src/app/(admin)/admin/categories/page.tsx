"use client";
import { useState, useEffect } from "react";
import { Plus, Edit, Trash2, FolderOpen } from "lucide-react";

interface Category { id:string;name:string;slug:string;description:string|null;color:string|null;isActive:boolean;sortOrder:number;_count:{posts:number} }

export default function CategoriesPage() {
  const [cats, setCats] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name:"", description:"", color:"#ea580c", sortOrder:0 });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetch("/api/categories").then(r=>r.json()).then(d=>{ setCats(d.data||[]); setLoading(false); });
  }, []);

  async function handleCreate() {
    if (!form.name) return;
    setSaving(true);
    const res = await fetch("/api/categories", { method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(form) });
    const data = await res.json();
    if (data.success) { setCats(c=>[...c, data.data]); setForm({name:"",description:"",color:"#ea580c",sortOrder:0}); setShowForm(false); }
    setSaving(false);
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div><h1 className="text-2xl font-heading font-bold">Categories</h1><p className="text-muted-foreground text-sm">{cats.length} categories</p></div>
        <button onClick={()=>setShowForm(!showForm)} className="btn-primary text-sm flex items-center gap-1.5"><Plus className="h-4 w-4" />New Category</button>
      </div>

      {showForm && (
        <div className="card p-5 space-y-4">
          <h3 className="font-semibold">Create Category</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label className="label">Name *</label><input value={form.name} onChange={e=>setForm(f=>({...f,name:e.target.value}))} className="input" placeholder="e.g. Markets" /></div>
            <div><label className="label">Color</label><div className="flex gap-2"><input value={form.color} onChange={e=>setForm(f=>({...f,color:e.target.value}))} className="input" placeholder="#ea580c" /><input type="color" value={form.color} onChange={e=>setForm(f=>({...f,color:e.target.value}))} className="h-10 w-12 cursor-pointer rounded border border-input" /></div></div>
            <div className="sm:col-span-2"><label className="label">Description</label><textarea value={form.description} onChange={e=>setForm(f=>({...f,description:e.target.value}))} className="input resize-none" rows={2} placeholder="Category description..." /></div>
            <div><label className="label">Sort Order</label><input type="number" value={form.sortOrder} onChange={e=>setForm(f=>({...f,sortOrder:parseInt(e.target.value)||0}))} className="input" /></div>
          </div>
          <div className="flex gap-2"><button onClick={handleCreate} disabled={saving} className="btn-primary text-sm">{saving?"Creating...":"Create Category"}</button><button onClick={()=>setShowForm(false)} className="btn-secondary text-sm">Cancel</button></div>
        </div>
      )}

      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-border bg-muted/50 text-left">
            <th className="p-3 font-medium text-muted-foreground">Category</th>
            <th className="p-3 font-medium text-muted-foreground">Slug</th>
            <th className="p-3 font-medium text-muted-foreground text-center">Posts</th>
            <th className="p-3 font-medium text-muted-foreground text-center">Status</th>
            <th className="p-3 font-medium text-muted-foreground text-center">Order</th>
            <th className="p-3 font-medium text-muted-foreground text-right">Actions</th>
          </tr></thead>
          <tbody>
            {loading ? Array.from({length:6}).map((_,i)=>(
              <tr key={i} className="border-b border-border/50"><td colSpan={6} className="p-3"><div className="h-4 bg-muted rounded skeleton w-full" /></td></tr>
            )) : cats.map(cat=>(
              <tr key={cat.id} className="border-b border-border/50 hover:bg-muted/30 transition-colors">
                <td className="p-3"><div className="flex items-center gap-3"><div className="h-8 w-8 rounded-lg flex items-center justify-center" style={{backgroundColor:`${cat.color||"#ea580c"}20`}}><FolderOpen className="h-4 w-4" style={{color:cat.color||"#ea580c"}} /></div><div><p className="font-medium">{cat.name}</p>{cat.description&&<p className="text-xs text-muted-foreground line-clamp-1">{cat.description}</p>}</div></div></td>
                <td className="p-3 text-muted-foreground font-mono text-xs">{cat.slug}</td>
                <td className="p-3 text-center"><span className="badge bg-muted text-muted-foreground">{cat._count.posts}</span></td>
                <td className="p-3 text-center"><span className={`badge text-xs ${cat.isActive?"bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400":"bg-gray-100 text-gray-500"}`}>{cat.isActive?"Active":"Inactive"}</span></td>
                <td className="p-3 text-center text-muted-foreground">{cat.sortOrder}</td>
                <td className="p-3"><div className="flex items-center justify-end gap-1"><button className="h-7 w-7 flex items-center justify-center rounded hover:bg-muted text-muted-foreground hover:text-brand-600 transition-colors"><Edit className="h-3.5 w-3.5" /></button><button className="h-7 w-7 flex items-center justify-center rounded hover:bg-red-50 dark:hover:bg-red-950/30 text-muted-foreground hover:text-red-600 transition-colors"><Trash2 className="h-3.5 w-3.5" /></button></div></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}