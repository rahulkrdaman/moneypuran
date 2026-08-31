import Link from "next/link";
import { QuotePanel } from "./QuotePanel";
import { PriceChart } from "./PriceChart";
import { JsonLd } from "@/components/seo/JsonLd";
import { breadcrumbLd, marketEntityLd } from "@/lib/seo/jsonld";
import type { SymbolMeta } from "@/lib/market";

const SITE = process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com";

const SECTION: Record<string, { label: string; base: string }> = {
  stock: { label: "Stocks", base: "/stocks" },
  etf: { label: "Stocks", base: "/stocks" },
  index: { label: "Markets", base: "/markets" },
  crypto: { label: "Crypto", base: "/crypto" },
  forex: { label: "Forex", base: "/forex" },
  commodity: { label: "Commodities", base: "/commodities" },
};

/**
 * Shared renderer for every market entity page. The live number arrives client-
 * side (QuotePanel / PriceChart); this server component owns SEO + the factual,
 * price-free description.
 */
export function EntityPage({ meta }: { meta: SymbolMeta }) {
  const sec = SECTION[meta.assetClass] ?? SECTION.stock;
  const url = `${SITE}${sec.base}/${meta.slug}`;

  return (
    <div className="container space-y-8 py-8">
      <JsonLd
        data={[
          breadcrumbLd([
            { name: "Home", url: "/" },
            { name: sec.label, url: sec.base },
            { name: meta.name, url: `${sec.base}/${meta.slug}` },
          ]),
          marketEntityLd({ url, name: meta.name, symbol: meta.symbol, assetClass: meta.assetClass, description: meta.description }),
        ]}
      />

      <nav className="text-xs text-muted-foreground">
        <Link href="/" className="hover:underline">Home</Link> ·{" "}
        <Link href={sec.base} className="hover:underline">{sec.label}</Link> ·{" "}
        <span className="text-foreground">{meta.name}</span>
      </nav>

      <header className="space-y-1">
        <h1 className="font-heading text-3xl font-bold">
          {meta.name} <span className="text-muted-foreground">({meta.symbol.replace("^", "")})</span>
        </h1>
        {meta.exchange && <p className="text-sm text-muted-foreground">{meta.exchange}{meta.market ? ` · ${meta.market}` : ""}</p>}
      </header>

      <QuotePanel symbol={meta.symbol} />

      <section className="rounded-lg border border-border p-4">
        <PriceChart symbol={meta.symbol} />
      </section>

      {meta.description && (
        <section className="prose prose-sm max-w-none dark:prose-invert">
          <h2>About {meta.name}</h2>
          <p>{meta.description}</p>
        </section>
      )}

      <section className="rounded-lg border border-border bg-muted/30 p-4 text-xs text-muted-foreground">
        <strong className="text-foreground">Not investment advice.</strong> Prices shown may be delayed,
        end-of-day, or demonstration data — the freshness label above is authoritative.
        Always confirm on an official source before trading.
      </section>

      {/* Placeholder sections wired in later phases (spec §14): Latest News,
          Earnings, Financials, Analyst Estimates, Competitors, Fundamentals,
          Technical Indicators. */}
    </div>
  );
}
