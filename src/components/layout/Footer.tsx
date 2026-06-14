import Link from "next/link";
import { Category } from "@/types";

interface FooterProps { categories: Category[]; }

export function Footer({ categories }: FooterProps) {
  const mainCats = categories.filter((c) => c.isActive).slice(0, 8);
  return (
    <footer className="bg-slate-900 text-slate-300 mt-12">
      {/* Newsletter CTA */}
      <div className="bg-brand-600">
        <div className="container py-10 flex flex-col md:flex-row items-center justify-between gap-6">
          <div>
            <h3 className="text-white text-xl font-heading font-bold">Stay Ahead of the Markets</h3>
            <p className="text-brand-100 text-sm mt-1">Get daily finance news, market updates, and investment insights.</p>
          </div>
          <form action="/api/newsletter" method="POST" className="flex gap-2 w-full md:w-auto">
            <input name="email" type="email" placeholder="Your email address" required
              className="input flex-1 md:w-72 bg-white text-slate-900 placeholder-slate-400" />
            <button type="submit" className="btn-primary flex-shrink-0">Subscribe Free</button>
          </form>
        </div>
      </div>

      {/* Main Footer */}
      <div className="container py-12 grid grid-cols-1 md:grid-cols-4 gap-8">
        {/* Brand */}
        <div className="col-span-1">
          <Link href="/" className="flex items-center gap-2 mb-4">
            <div className="h-9 w-9 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold text-lg">₹</div>
            <span className="text-white font-heading font-bold text-xl">MoneyPuran</span>
          </Link>
          <p className="text-sm leading-relaxed mb-4">India's trusted source for finance, business, markets, and economy news. Stay informed, stay ahead.</p>
          <div className="flex gap-3">
            {[{href:"https://twitter.com/moneypuran",label:"Twitter",icon:"𝕏"},
              {href:"https://linkedin.com/company/moneypuran",label:"LinkedIn",icon:"in"},
              {href:"https://telegram.me/moneypuran",label:"Telegram",icon:"✈"},
              {href:"https://youtube.com/@moneypuran",label:"YouTube",icon:"▶"}
            ].map(({href,label,icon}) => (
              <a key={label} href={href} target="_blank" rel="noopener noreferrer" aria-label={label}
                className="h-8 w-8 rounded-lg bg-slate-700 hover:bg-brand-600 flex items-center justify-center text-xs font-bold transition-colors">
                {icon}
              </a>
            ))}
          </div>
        </div>

        {/* Categories */}
        <div>
          <h4 className="text-white font-semibold mb-4">Categories</h4>
          <ul className="space-y-2 text-sm">
            {mainCats.map((cat) => (
              <li key={cat.id}><Link href={`/category/${cat.slug}`} className="hover:text-white hover:text-brand-400 transition-colors">{cat.name}</Link></li>
            ))}
          </ul>
        </div>

        {/* Quick Links */}
        <div>
          <h4 className="text-white font-semibold mb-4">Quick Links</h4>
          <ul className="space-y-2 text-sm">
            {[{href:"/about",label:"About Us"},{href:"/contact",label:"Contact"},{href:"/advertise",label:"Advertise With Us"},
              {href:"/careers",label:"Careers"},{href:"/newsletter",label:"Newsletter"},{href:"/rss",label:"RSS Feed"},
              {href:"/sitemap.xml",label:"Sitemap"}
            ].map(({href,label}) => (
              <li key={href}><Link href={href} className="hover:text-brand-400 transition-colors">{label}</Link></li>
            ))}
          </ul>
        </div>

        {/* Legal */}
        <div>
          <h4 className="text-white font-semibold mb-4">Legal</h4>
          <ul className="space-y-2 text-sm">
            {[{href:"/privacy-policy",label:"Privacy Policy"},{href:"/terms",label:"Terms of Service"},
              {href:"/disclaimer",label:"Disclaimer"},{href:"/cookie-policy",label:"Cookie Policy"},
              {href:"/corrections",label:"Corrections Policy"}
            ].map(({href,label}) => (
              <li key={href}><Link href={href} className="hover:text-brand-400 transition-colors">{label}</Link></li>
            ))}
          </ul>
          <div className="mt-6 p-3 bg-slate-800 rounded-lg text-xs">
            <p className="font-medium text-yellow-400 mb-1">⚠ Investment Disclaimer</p>
            <p>Content on MoneyPuran is for informational purposes only. Not investment advice. Consult a financial advisor.</p>
          </div>
        </div>
      </div>

      {/* Bottom Bar */}
      <div className="border-t border-slate-700">
        <div className="container py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500">
          <p>© {new Date().getFullYear()} MoneyPuran. All rights reserved. SEBI Registration: Not Applicable.</p>
          <div className="flex items-center gap-4">
            <Link href="/privacy-policy" className="hover:text-slate-300 transition-colors">Privacy</Link>
            <Link href="/terms" className="hover:text-slate-300 transition-colors">Terms</Link>
            <Link href="/sitemap.xml" className="hover:text-slate-300 transition-colors">Sitemap</Link>
          </div>
        </div>
      </div>
    </footer>
  );
}
