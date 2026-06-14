"use client";
import { useState, useEffect } from "react";
import { Globe, Save, RefreshCw, CheckCircle, AlertCircle, ExternalLink, Search } from "lucide-react";

interface SeoSettings {
  id: string;
  siteName: string; tagline: string; siteUrl: string;
  defaultMetaTitle: string; defaultMetaDescription: string; defaultMetaKeywords: string;
  ogImage: string | null; twitterHandle: string | null; googleAnalyticsId: string | null;
  googleSearchConsoleId: string | null; robotsTxt: string | null; sitemapEnabled: boolean;
  schemaEnabled: boolean; ampEnabled: boolean; canonicalEnabled: boolean;
}

const defaultRobots = `User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/

Sitemap: https://moneypuran.com/sitemap.xml`;

const TABS = ["General", "Social & OG", "Search Console", "Technical", "Robots.txt"] as const;
type Tab = typeof TABS[number];

export default function SeoPage() {
  const [settings, setSettings] = useState<Partial<SeoSettings>>({
    siteName: "MoneyPuran", tagline: "India's Trusted Finance & Business News",
    siteUrl: "https://moneypuran.com", defaultMetaTitle: "", defaultMetaDescription: "",
    defaultMetaKeywords: "", twitterHandle: "@moneypuran", sitemapEnabled: true,
    schemaEnabled: true, ampEnabled: true, canonicalEnabled: true,
    robotsTxt: defaultRobots,
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [tab, setTab] = useState<Tab>("General");
  const [charCount, setCharCount] = useState({ title: 0, desc: 0 });

  useEffect(() => {
    fetch("/api/seo").then(r => r.json()).then(d => {
      if (d.success && d.data) setSettings(d.data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  useEffect(() => {
    setCharCount({
      title: (settings.defaultMetaTitle || "").length,
      desc: (settings.defaultMetaDescription || "").length,
    });
  }, [settings.defaultMetaTitle, settings.defaultMetaDescription]);

  async function handleSave() {
    setSaving(true);
    await fetch("/api/seo", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(settings) });
    setSaving(false); setSaved(true);
    setTimeout(() => setSaved(false), 3000);
  }

  function upd(key: keyof SeoSettings, val: string | boolean) {
    setSettings(s => ({ ...s, [key]: val }));
  }

  const Toggle = ({ label, desc, field }: { label: string; desc: string; field: keyof SeoSettings }) => (
    <div className="flex items-start justify-between gap-4 py-3 border-b border-border last:border-0">
      <div>
        <p className="font-medium text-sm">{label}</p>
        <p className="text-xs text-muted-foreground">{desc}</p>
      </div>
      <button type="button" onClick={() => upd(field, !settings[field])}
        className={`relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors ${settings[field] ? "bg-brand-600" : "bg-muted"}`}>
        <span className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${settings[field] ? "translate-x-5" : "translate-x-0"}`} />
      </button>
    </div>
  );

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-heading font-bold">SEO Settings</h1>
          <p className="text-muted-foreground text-sm">Global SEO configuration for MoneyPuran</p>
        </div>
        <div className="flex gap-2">
          <a href="/api/sitemap" target="_blank" className="btn-secondary text-sm flex items-center gap-1.5">
            <ExternalLink className="h-4 w-4" /> View Sitemap
          </a>
          <button onClick={handleSave} disabled={saving || loading} className="btn-primary text-sm flex items-center gap-1.5">
            {saving ? <RefreshCw className="h-4 w-4 animate-spin" /> : saved ? <CheckCircle className="h-4 w-4" /> : <Save className="h-4 w-4" />}
            {saving ? "Saving..." : saved ? "Saved!" : "Save Changes"}
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-border overflow-x-auto">
        {TABS.map(t => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-4 py-2.5 text-sm font-medium transition-colors whitespace-nowrap border-b-2 -mb-px ${tab === t ? "border-brand-600 text-brand-600" : "border-transparent text-muted-foreground hover:text-foreground"}`}>
            {t}
          </button>
        ))}
      </div>

      <div className="card p-5">
        {tab === "General" && (
          <div className="space-y-4">
            <h3 className="font-semibold mb-2">Site Information</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div><label className="label">Site Name</label>
                <input value={settings.siteName || ""} onChange={e => upd("siteName", e.target.value)} className="input" placeholder="MoneyPuran" /></div>
              <div><label className="label">Tagline</label>
                <input value={settings.tagline || ""} onChange={e => upd("tagline", e.target.value)} className="input" placeholder="India's Finance News" /></div>
              <div className="sm:col-span-2"><label className="label">Site URL</label>
                <input value={settings.siteUrl || ""} onChange={e => upd("siteUrl", e.target.value)} className="input" placeholder="https://moneypuran.com" /></div>
            </div>
            <hr className="border-border" />
            <h3 className="font-semibold mb-2">Default SEO Meta</h3>
            <div>
              <div className="flex justify-between mb-1"><label className="label !mb-0">Default Meta Title</label>
                <span className={`text-xs ${charCount.title > 60 ? "text-red-500" : "text-muted-foreground"}`}>{charCount.title}/60</span>
              </div>
              <input value={settings.defaultMetaTitle || ""} onChange={e => upd("defaultMetaTitle", e.target.value)}
                className="input" placeholder="MoneyPuran – India's Finance & Business News" />
              <p className="text-xs text-muted-foreground mt-1">Recommended: 50–60 characters</p>
            </div>
            <div>
              <div className="flex justify-between mb-1"><label className="label !mb-0">Default Meta Description</label>
                <span className={`text-xs ${charCount.desc > 160 ? "text-red-500" : charCount.desc > 140 ? "text-amber-500" : "text-muted-foreground"}`}>{charCount.desc}/160</span>
              </div>
              <textarea value={settings.defaultMetaDescription || ""} onChange={e => upd("defaultMetaDescription", e.target.value)}
                className="input resize-none" rows={3} placeholder="Get the latest finance, stock market, and business news..." />
              <p className="text-xs text-muted-foreground mt-1">Recommended: 150–160 characters</p>
            </div>
            <div><label className="label">Default Keywords</label>
              <input value={settings.defaultMetaKeywords || ""} onChange={e => upd("defaultMetaKeywords", e.target.value)}
                className="input" placeholder="finance news, stock market, business, economy, India" /></div>

            {/* SERP Preview */}
            <div className="mt-4 p-4 rounded-lg border border-border bg-muted/30">
              <p className="text-xs text-muted-foreground mb-3 font-medium flex items-center gap-1.5"><Search className="h-3.5 w-3.5" /> SERP Preview</p>
              <div className="space-y-0.5">
                <p className="text-blue-600 dark:text-blue-400 text-sm font-medium">{settings.defaultMetaTitle || "Page Title"}</p>
                <p className="text-green-600 dark:text-green-500 text-xs">{settings.siteUrl || "https://moneypuran.com"}</p>
                <p className="text-muted-foreground text-xs leading-relaxed">{settings.defaultMetaDescription || "Your meta description will appear here..."}</p>
              </div>
            </div>
          </div>
        )}

        {tab === "Social & OG" && (
          <div className="space-y-4">
            <h3 className="font-semibold mb-2">Open Graph & Social</h3>
            <div><label className="label">Default OG Image URL</label>
              <input value={settings.ogImage || ""} onChange={e => upd("ogImage", e.target.value)} className="input" placeholder="https://moneypuran.com/og-default.jpg" />
              <p className="text-xs text-muted-foreground mt-1">Recommended: 1200×630px JPG/PNG</p></div>
            <div><label className="label">Twitter Handle</label>
              <input value={settings.twitterHandle || ""} onChange={e => upd("twitterHandle", e.target.value)} className="input" placeholder="@moneypuran" /></div>
            {settings.ogImage && (
              <div className="border border-border rounded-lg overflow-hidden max-w-md">
                <img src={settings.ogImage} alt="OG Preview" className="w-full h-auto" onError={e => { (e.target as HTMLImageElement).style.display = "none"; }} />
                <div className="p-3 bg-muted/40">
                  <p className="text-xs text-muted-foreground uppercase">moneypuran.com</p>
                  <p className="text-sm font-medium">{settings.defaultMetaTitle}</p>
                  <p className="text-xs text-muted-foreground">{settings.defaultMetaDescription?.slice(0, 80)}...</p>
                </div>
              </div>
            )}
          </div>
        )}

        {tab === "Search Console" && (
          <div className="space-y-4">
            <h3 className="font-semibold mb-2">Google Integration</h3>
            <div><label className="label">Google Analytics Measurement ID</label>
              <input value={settings.googleAnalyticsId || ""} onChange={e => upd("googleAnalyticsId", e.target.value)} className="input" placeholder="G-XXXXXXXXXX" /></div>
            <div><label className="label">Google Search Console Verification ID</label>
              <input value={settings.googleSearchConsoleId || ""} onChange={e => upd("googleSearchConsoleId", e.target.value)} className="input" placeholder="Paste the content attribute value" /></div>
            <div className="p-4 rounded-lg bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 text-sm text-blue-800 dark:text-blue-200">
              <p className="font-medium mb-1">How to verify in Google Search Console:</p>
              <ol className="list-decimal list-inside space-y-1 text-xs">
                <li>Go to Google Search Console and add your property</li>
                <li>Choose "HTML tag" verification method</li>
                <li>Copy the <code className="bg-blue-100 dark:bg-blue-900 px-1 rounded">content</code> value from the meta tag</li>
                <li>Paste it in the field above and save</li>
              </ol>
            </div>
          </div>
        )}

        {tab === "Technical" && (
          <div className="space-y-1">
            <h3 className="font-semibold mb-3">Technical SEO Features</h3>
            <Toggle label="XML Sitemap" desc="Auto-generate sitemap.xml at /sitemap.xml" field="sitemapEnabled" />
            <Toggle label="Schema.org Markup" desc="Add JSON-LD structured data to articles and pages" field="schemaEnabled" />
            <Toggle label="AMP Pages" desc="Generate Accelerated Mobile Pages for articles" field="ampEnabled" />
            <Toggle label="Canonical Tags" desc="Add canonical URL meta tags to all pages" field="canonicalEnabled" />
            <div className="mt-4 pt-4 border-t border-border">
              <p className="text-sm text-muted-foreground">
                <AlertCircle className="h-4 w-4 inline mr-1 text-amber-500" />
                Disabling these features may affect search engine rankings.
              </p>
            </div>
          </div>
        )}

        {tab === "Robots.txt" && (
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <div><h3 className="font-semibold">Robots.txt</h3>
                <p className="text-xs text-muted-foreground">Controls crawler access. Served at /robots.txt</p>
              </div>
              <button onClick={() => upd("robotsTxt", defaultRobots)} className="btn-secondary text-xs">Reset to Default</button>
            </div>
            <textarea value={settings.robotsTxt || ""} onChange={e => upd("robotsTxt", e.target.value)}
              className="input resize-none font-mono text-xs h-64" placeholder={defaultRobots} />
          </div>
        )}
      </div>
    </div>
  );
}
