"use client";
import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { Save, Eye, Image, Tag, Settings } from "lucide-react";

interface Category { id:string; name:string; slug:string }
interface TagItem { id:string; name:string }

export default function NewPostPage() {
  const router = useRouter();
  const [saving, setSaving] = useState(false);
  const [categories, setCategories] = useState<Category[]>([]);
  const [allTags, setAllTags] = useState<TagItem[]>([]);
  const [selectedTags, setSelectedTags] = useState<string[]>([]);
  const [form, setForm] = useState({
    title:"", content:"", excerpt:"", categoryId:"", status:"DRAFT", postType:"ARTICLE",
    featuredImage:"", metaTitle:"", metaDesc:"", isFeatured:false, isTrending:false, isBreaking:false,
  });

  useEffect(() => {
    Promise.all([fetch("/api/categories").then(r=>r.json()), fetch("/api/tags").then(r=>r.json())])
      .then(([cats, tags]) => { setCategories(cats.data||[]); setAllTags(tags.data||[]); });
  }, []);

  const set = (k:string, v:unknown) => setForm(f=>({...f,[k]:v}));

  async function handleSave(status="DRAFT") {
    if (!form.title || !form.content || !form.categoryId) { alert("Title, content and category are required"); return; }
    setSaving(true);
    const res = await fetch("/api/posts", { method:"POST", headers:{"Content-Type":"application/json"},
      body: JSON.stringify({...form, status, tagIds:selectedTags}) });
    const data = await res.json();
    if (data.success) router.push(`/admin/posts/${data.data.id}/edit`);
    else alert(data.error || "Failed to save");
    setSaving(false);
  }

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div><h1 className="text-2xl font-heading font-bold">New Post</h1><p className="text-muted-foreground text-sm">Create a new article</p></div>
        <div className="flex gap-2">
          <button onClick={()=>handleSave("DRAFT")} disabled={saving} className="btn-secondary text-sm"><Save className="h-4 w-4 mr-1.5" />Save Draft</button>
          <button onClick={()=>handleSave("REVIEW")} disabled={saving} className="btn-secondary text-sm">Send to Review</button>
          <button onClick={()=>handleSave("PUBLISHED")} disabled={saving} className="btn-primary text-sm">Publish Now</button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Editor */}
        <div className="lg:col-span-2 space-y-4">
          <div className="card p-5 space-y-4">
            <div>
              <label className="label">Title *</label>
              <input value={form.title} onChange={e=>set("title",e.target.value)} className="input text-lg font-semibold" placeholder="Enter article title..." />
            </div>
            <div>
              <label className="label">Excerpt / Summary</label>
              <textarea value={form.excerpt} onChange={e=>set("excerpt",e.target.value)} rows={2} className="input resize-none" placeholder="Brief summary shown in listings..." />
            </div>
            <div>
              <label className="label">Content *</label>
              <textarea value={form.content} onChange={e=>set("content",e.target.value)} rows={20} className="input font-mono text-sm resize-y" placeholder="Write your article content here (HTML supported)..." />
              <p className="text-xs text-muted-foreground mt-1">HTML is supported. Use &lt;h2&gt;, &lt;h3&gt;, &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;ul&gt;, &lt;blockquote&gt; tags.</p>
            </div>
          </div>

          {/* SEO */}
          <div className="card p-5 space-y-4">
            <h3 className="font-semibold flex items-center gap-2"><Settings className="h-4 w-4" />SEO Settings</h3>
            <div>
              <label className="label">Meta Title <span className="text-xs text-muted-foreground">(50-60 chars)</span></label>
              <input value={form.metaTitle} onChange={e=>set("metaTitle",e.target.value)} className="input text-sm" placeholder="SEO optimized title..." maxLength={70} />
              <p className="text-xs text-muted-foreground mt-1">{form.metaTitle.length}/70 characters</p>
            </div>
            <div>
              <label className="label">Meta Description <span className="text-xs text-muted-foreground">(150-160 chars)</span></label>
              <textarea value={form.metaDesc} onChange={e=>set("metaDesc",e.target.value)} rows={2} className="input text-sm resize-none" placeholder="SEO meta description..." maxLength={160} />
              <p className="text-xs text-muted-foreground mt-1">{form.metaDesc.length}/160 characters</p>
            </div>
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          <div className="card p-5 space-y-4">
            <h3 className="font-semibold">Publish Settings</h3>
            <div>
              <label className="label">Status</label>
              <select value={form.status} onChange={e=>set("status",e.target.value)} className="input text-sm">
                {["DRAFT","REVIEW","SCHEDULED","PUBLISHED"].map(s=><option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Post Type</label>
              <select value={form.postType} onChange={e=>set("postType",e.target.value)} className="input text-sm">
                {["ARTICLE","ANALYSIS","OPINION","PRESS_RELEASE","SPONSORED"].map(t=><option key={t} value={t}>{t.replace("_"," ")}</option>)}
              </select>
            </div>
            <div className="space-y-2">
              {[{k:"isFeatured",l:"⭐ Featured Post"},{k:"isTrending",l:"🔥 Trending"},{k:"isBreaking",l:"🔴 Breaking News"}].map(({k,l})=>(
                <label key={k} className="flex items-center gap-2 cursor-pointer">
                  <input type="checkbox" checked={form[k as keyof typeof form] as boolean} onChange={e=>set(k,e.target.checked)} className="rounded" />
                  <span className="text-sm">{l}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="card p-5 space-y-3">
            <h3 className="font-semibold">Category *</h3>
            <select value={form.categoryId} onChange={e=>set("categoryId",e.target.value)} className="input text-sm">
              <option value="">Select category...</option>
              {categories.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>

          <div className="card p-5 space-y-3">
            <h3 className="font-semibold flex items-center gap-2"><Tag className="h-4 w-4" />Tags</h3>
            <div className="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto">
              {allTags.map(t=>(
                <button key={t.id} type="button"
                  onClick={()=>setSelectedTags(prev=>prev.includes(t.id)?prev.filter(id=>id!==t.id):[...prev,t.id])}
                  className={`text-xs px-2.5 py-1 rounded-full border transition-all ${selectedTags.includes(t.id)?"bg-brand-600 text-white border-brand-600":"border-border hover:border-brand-400"}`}>
                  {t.name}
                </button>
              ))}
            </div>
          </div>

          <div className="card p-5 space-y-3">
            <h3 className="font-semibold flex items-center gap-2"><Image className="h-4 w-4" />Featured Image</h3>
            <input value={form.featuredImage} onChange={e=>set("featuredImage",e.target.value)} className="input text-sm" placeholder="https://..." />
            {form.featuredImage && <img src={form.featuredImage} alt="Preview" className="w-full rounded-lg object-cover h-32" />}
          </div>
        </div>
      </div>
    </div>
  );
}