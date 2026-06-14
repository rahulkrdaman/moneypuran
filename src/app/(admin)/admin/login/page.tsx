"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";

export default function AdminLogin() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const router = useRouter();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault(); setError(""); setLoading(true);
    try {
      const res = await fetch("/api/auth/login", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const data = await res.json();
      if (data.success) router.push("/admin");
      else setError(data.error || "Login failed");
    } catch { setError("Network error. Please try again."); }
    setLoading(false);
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-brand-900 flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        <div className="text-center mb-8">
          <div className="h-16 w-16 rounded-2xl bg-brand-600 flex items-center justify-center text-white font-bold text-3xl mx-auto mb-4 shadow-lg shadow-brand-600/40">₹</div>
          <h1 className="text-2xl font-heading font-bold text-white">MoneyPuran Admin</h1>
          <p className="text-slate-400 mt-1 text-sm">Sign in to manage your news portal</p>
        </div>
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl p-8">
          {error && <div className="mb-4 p-3 bg-red-50 dark:bg-red-950/50 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">{error}</div>}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="label">Email Address</label>
              <input type="email" value={email} onChange={e=>setEmail(e.target.value)} required className="input" placeholder="admin@moneypuran.com" />
            </div>
            <div>
              <label className="label">Password</label>
              <input type="password" value={password} onChange={e=>setPassword(e.target.value)} required className="input" placeholder="••••••••" />
            </div>
            <button type="submit" disabled={loading} className="btn-primary w-full h-10 mt-2">
              {loading ? "Signing in..." : "Sign In →"}
            </button>
          </form>
          <p className="text-center text-xs text-muted-foreground mt-6">
            <a href="/" className="hover:text-brand-600 transition-colors">← Back to MoneyPuran</a>
          </p>
        </div>
      </div>
    </div>
  );
}