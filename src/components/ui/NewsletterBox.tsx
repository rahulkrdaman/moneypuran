"use client";
import { useState } from "react";
import { Mail, CheckCircle } from "lucide-react";

export function NewsletterBox() {
  const [email, setEmail] = useState("");
  const [status, setStatus] = useState<"idle"|"loading"|"success"|"error">("idle");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setStatus("loading");
    try {
      const res = await fetch("/api/newsletter", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email }),
      });
      if (res.ok) { setStatus("success"); setEmail(""); }
      else setStatus("error");
    } catch { setStatus("error"); }
  }

  return (
    <div className="card p-5 bg-gradient-to-br from-brand-50 to-orange-50 dark:from-brand-950/30 dark:to-orange-950/20 border-brand-100 dark:border-brand-900">
      <div className="flex items-center gap-2 mb-3">
        <Mail className="h-5 w-5 text-brand-600" />
        <h3 className="font-heading font-bold text-base">Daily Market Digest</h3>
      </div>
      <p className="text-sm text-muted-foreground mb-4">Get curated finance news, market analysis, and investment insights delivered every morning.</p>
      {status === "success" ? (
        <div className="flex items-center gap-2 text-sm text-finance-green font-medium">
          <CheckCircle className="h-4 w-4" />
          <span>You're subscribed! Check your inbox.</span>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-2">
          <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="Enter your email" required className="input text-sm" />
          <button type="submit" disabled={status === "loading"} className="btn-primary w-full text-sm">
            {status === "loading" ? "Subscribing..." : "Subscribe Free →"}
          </button>
          {status === "error" && <p className="text-xs text-red-500">Something went wrong. Try again.</p>}
          <p className="text-xs text-muted-foreground">No spam. Unsubscribe anytime.</p>
        </form>
      )}
    </div>
  );
}

export default NewsletterBox;
