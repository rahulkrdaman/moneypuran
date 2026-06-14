"use client";
import { useState, useEffect } from "react";
import { Save, Globe, Bot, Bell, Shield } from "lucide-react";

export default function SettingsPage() {
  const [aiForm, setAiForm] = useState({ model:"gpt-4o-mini", temperature:0.7, qualityThreshold:0.7, maxDailyArticles:50, autoPublish:false, humanReviewRequired:true, generateImages:true, scheduleEnabled:true, scheduleInterval:60 });
  const [seoForm, setSeoForm] = useState({ siteName:"MoneyPuran", tagline:"", siteUrl:"", googleAnalyticsId:"",  twitterHandle:"" });
  const [saving, setSaving] = useState(false);
  const [tab, setTab] = useState<"ai"|"seo"|"smtp">("ai");

  async function handleSaveAI() {
    setSaving(true);
    await fetch("/api/ai-settings", { method:"PUT", headers:{"Content-Type":"application/json"}, body:JSON.stringify(aiForm) });
    setSaving(false);
    alert("AI Settings saved!");
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div><h1 className="text-2xl font-heading font-bold">Settings</h1><p className="text-muted-foreground text-sm">Configure your news platform</p></div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 bg-muted rounded-lg w-fit">
        {[{id:"ai",label:"AI Agent",icon:Bot},{id:"seo",label:"SEO",icon:Globe},{id:"smtp",label:"Email",icon:Bell}].map(t=>(
          <button key={t.id} onClick={()=>setTab(t.id as any)} className={`flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all ${tab===t.id?"bg-card shadow text-foreground":"text-muted-foreground hover:text-foreground"}`}>
            <t.icon className="h-4 w-4" />{t.label}
          </button>
        ))}
      </div>

      {tab === "ai" && (
        <div className="card p-6 space-y-5">
          <h2 className="font-semibold flex items-center gap-2"><Bot className="h-5 w-5 text-purple-500" />AI Agent Settings</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label className="label">Model</label><select value={aiForm.model} onChange={e=>setAiForm(f=>({...f,model:e.target.value}))} className="input text-sm"><option value="gpt-4o-mini">GPT-4o Mini (Fast)</option><option value="gpt-4o">GPT-4o (Best)</option><option value="gpt-3.5-turbo">GPT-3.5 Turbo (Cheap)</option></select></div>
            <div><label className="label">Temperature ({aiForm.temperature})</label><input type="range" min={0} max={1} step={0.1} value={aiForm.temperature} onChange={e=>setAiForm(f=>({...f,temperature:parseFloat(e.target.value)}))} className="w-full mt-2" /></div>
            <div><label className="label">Quality Threshold ({(aiForm.qualityThreshold*100).toFixed(0)}%)</label><input type="range" min={0.5} max={1} step={0.05} value={aiForm.qualityThreshold} onChange={e=>setAiForm(f=>({...f,qualityThreshold:parseFloat(e.target.value)}))} className="w-full mt-2" /></div>
            <div><label className="label">Max Daily Articles</label><input type="number" value={aiForm.maxDailyArticles} onChange={e=>setAiForm(f=>({...f,maxDailyArticles:parseInt(e.target.value)||50}))} className="input text-sm" min={1} max={500} /></div>
            <div><label className="label">Schedule Interval (minutes)</label><input type="number" value={aiForm.scheduleInterval} onChange={e=>setAiForm(f=>({...f,scheduleInterval:parseInt(e.target.value)||60}))} className="input text-sm" min={15} /></div>
          </div>
          <div className="space-y-3 border-t border-border pt-4">
            {[{k:"autoPublish",l:"Auto-publish articles (skip review)"},{k:"humanReviewRequired",l:"Require human review for all AI content"},{k:"generateImages",l:"Generate featured images with DALL-E"},{k:"scheduleEnabled",l:"Enable scheduled RSS fetching"}].map(({k,l})=>(
              <label key={k} className="flex items-center gap-3 cursor-pointer">
                <div className={`relative w-10 h-6 rounded-full transition-colors ${aiForm[k as keyof typeof aiForm]?"bg-brand-600":"bg-muted"} cursor-pointer`}
                  onClick={()=>setAiForm(f=>({...f,[k]:!f[k as keyof typeof f]}))}>
                  <div className={`absolute top-1 h-4 w-4 bg-white rounded-full shadow transition-transform ${aiForm[k as keyof typeof aiForm]?"translate-x-5":"translate-x-1"}`} />
                </div>
                <span className="text-sm">{l}</span>
              </label>
            ))}
          </div>
          <button onClick={handleSaveAI} disabled={saving} className="btn-primary text-sm flex items-center gap-2"><Save className="h-4 w-4" />{saving?"Saving...":"Save AI Settings"}</button>
        </div>
      )}

      {tab === "seo" && (
        <div className="card p-6 space-y-4">
          <h2 className="font-semibold flex items-center gap-2"><Globe className="h-5 w-5 text-blue-500" />SEO & Analytics</h2>
          <div className="grid grid-cols-1 gap-4">
            <div><label className="label">Site Name</label><input value={seoForm.siteName} onChange={e=>setSeoForm(f=>({...f,siteName:e.target.value}))} className="input" /></div>
            <div><label className="label">Site Description</label><textarea value={seoForm.tagline} onChange={e=>setSeoForm(f=>({...f,tagline:e.target.value}))} rows={2} className="input resize-none" /></div>
            <div><label className="label">Site URL</label><input value={seoForm.siteUrl} onChange={e=>setSeoForm(f=>({...f,siteUrl:e.target.value}))} className="input" placeholder="https://moneypuran.com" /></div>
            <div><label className="label">Google Analytics ID</label><input value={seoForm.googleAnalyticsId} onChange={e=>setSeoForm(f=>({...f,googleAnalyticsId:e.target.value}))} className="input" placeholder="G-XXXXXXXXXX" /></div>
            <div><label className="label">Twitter Handle</label><input value={seoForm.twitterHandle} onChange={e=>setSeoForm(f=>({...f,twitterHandle:e.target.value}))} className="input" placeholder="@moneypuran" /></div>
          </div>
          <button className="btn-primary text-sm flex items-center gap-2"><Save className="h-4 w-4" />Save SEO Settings</button>
        </div>
      )}

      {tab === "smtp" && (
        <div className="card p-6 space-y-4">
          <h2 className="font-semibold flex items-center gap-2"><Bell className="h-5 w-5 text-green-500" />Email Settings</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label className="label">SMTP Host</label><input className="input" placeholder="smtp.gmail.com" /></div>
            <div><label className="label">SMTP Port</label><input type="number" className="input" placeholder="587" /></div>
            <div><label className="label">SMTP User</label><input type="email" className="input" placeholder="noreply@moneypuran.com" /></div>
            <div><label className="label">SMTP Password</label><input type="password" className="input" /></div>
            <div className="sm:col-span-2"><label className="label">From Email</label><input className="input" placeholder="MoneyPuran &lt;noreply@moneypuran.com&gt;" /></div>
          </div>
          <button className="btn-primary text-sm flex items-center gap-2"><Save className="h-4 w-4" />Save Email Settings</button>
        </div>
      )}
    </div>
  );
}