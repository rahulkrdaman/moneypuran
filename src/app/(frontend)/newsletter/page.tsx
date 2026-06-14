import type { Metadata } from "next";
import NewsletterBox from "@/components/ui/NewsletterBox";
import { TrendingUp, Mail, Clock, ShieldCheck, Sparkles } from "lucide-react";

export const metadata: Metadata = {
  title: "Newsletter | MoneyPuran – India's Finance & Business News",
  description: "Subscribe to MoneyPuran's free newsletter for daily finance news, market updates, and investment insights delivered to your inbox every morning.",
};

const BENEFITS = [
  { icon: TrendingUp, title: "Market Pulse", desc: "Daily Sensex, Nifty and global market roundup before you start your day" },
  { icon: Sparkles, title: "AI-Curated Insights", desc: "Our AI agent surfaces the most relevant stories from 50+ finance sources" },
  { icon: Clock, title: "Morning Briefing", desc: "Delivered at 7 AM IST every weekday — read in under 5 minutes" },
  { icon: ShieldCheck, title: "No Spam. Ever.", desc: "Unsubscribe with one click. We never sell your email address." },
];

const SAMPLE = [
  { emoji: "📈", text: "Nifty50 closes at record high, IT stocks lead the rally" },
  { emoji: "🏦", text: "RBI keeps repo rate unchanged; policy stance stays at 'withdrawal of accommodation'" },
  { emoji: "💰", text: "Budget 2025: Key takeaways for individual taxpayers" },
  { emoji: "🏭", text: "Zomato acquires Blinkit parent; delivery war heats up" },
  { emoji: "🌍", text: "FII inflows hit ₹12,000 Cr in October — highest since 2022" },
];

export default function NewsletterPage() {
  return (
    <main className="container py-12">
      {/* Hero */}
      <div className="max-w-2xl mx-auto text-center mb-12">
        <div className="inline-flex items-center gap-2 bg-brand-50 dark:bg-brand-950 text-brand-700 dark:text-brand-300 text-sm font-medium px-4 py-2 rounded-full mb-4">
          <Mail className="h-4 w-4" /> Free Daily Newsletter
        </div>
        <h1 className="text-4xl sm:text-5xl font-heading font-bold leading-tight mb-4">
          India&apos;s Finance News,<br />
          <span className="text-brand-600">In Your Inbox.</span>
        </h1>
        <p className="text-lg text-muted-foreground">
          Over 50,000 investors, traders and professionals trust MoneyPuran for their morning market briefing. Join them — it&apos;s free.
        </p>
      </div>

      {/* Subscribe Form */}
      <div className="max-w-md mx-auto mb-14">
        <div className="card p-6 shadow-lg">
          <NewsletterBox />
          <p className="text-center text-xs text-muted-foreground mt-3">
            Free forever · Unsubscribe anytime · No spam
          </p>
        </div>
      </div>

      {/* Benefits */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-14">
        {BENEFITS.map(({ icon: Icon, title, desc }) => (
          <div key={title} className="card p-5 text-center group hover:border-brand-300 transition-colors">
            <div className="inline-flex items-center justify-center h-12 w-12 rounded-xl bg-brand-50 dark:bg-brand-950 text-brand-600 mb-3 group-hover:scale-110 transition-transform">
              <Icon className="h-6 w-6" />
            </div>
            <h3 className="font-semibold mb-1">{title}</h3>
            <p className="text-sm text-muted-foreground">{desc}</p>
          </div>
        ))}
      </div>

      {/* Sample Issue */}
      <div className="max-w-2xl mx-auto">
        <h2 className="text-xl font-heading font-bold text-center mb-6">What a typical issue looks like</h2>
        <div className="card overflow-hidden shadow-lg">
          {/* Email Header */}
          <div className="bg-brand-600 text-white px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-lg bg-white/20 flex items-center justify-center font-bold text-xl">₹</div>
              <div>
                <p className="font-bold">MoneyPuran Morning Brief</p>
                <p className="text-brand-200 text-sm">{new Date().toLocaleDateString("en-IN", { weekday: "long", month: "long", day: "numeric" })}</p>
              </div>
            </div>
          </div>
          {/* Email Body */}
          <div className="px-6 py-5">
            <p className="text-sm text-muted-foreground mb-4">Good morning! Here&apos;s what&apos;s moving the markets today:</p>
            <div className="space-y-3">
              {SAMPLE.map(({ emoji, text }) => (
                <div key={text} className="flex items-start gap-3 text-sm">
                  <span className="text-lg flex-shrink-0">{emoji}</span>
                  <p>{text}</p>
                </div>
              ))}
            </div>
            <div className="mt-5 pt-5 border-t border-border">
              <p className="text-xs text-muted-foreground text-center">
                Read the full stories on{" "}
                <span className="text-brand-600 font-medium">moneypuran.com</span>
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Social proof */}
      <div className="mt-12 text-center">
        <p className="text-4xl font-bold font-heading text-brand-600 mb-1">50,000+</p>
        <p className="text-muted-foreground">subscribers trust MoneyPuran</p>
        <div className="flex justify-center gap-6 mt-6 flex-wrap">
          {["Daily Market Digest", "IPO Alerts", "Budget Specials", "RBI Policy Updates"].map(t => (
            <span key={t} className="text-sm badge bg-muted text-muted-foreground px-3 py-1">{t}</span>
          ))}
        </div>
      </div>
    </main>
  );
}
