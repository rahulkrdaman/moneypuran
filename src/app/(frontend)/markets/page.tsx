import type { Metadata } from "next";
import { MarketTable } from "@/components/market/MarketTable";
import { JsonLd } from "@/components/seo/JsonLd";
import { breadcrumbLd } from "@/lib/seo/jsonld";

export const metadata: Metadata = {
  title: "Global Markets Today — Indices, Crypto, Commodities & FX",
  description:
    "Live view of world markets: the S&P 500, Nasdaq, Dow, FTSE 100, DAX, Nikkei, Hang Seng, Nifty 50 and Sensex, plus Bitcoin, gold, oil and major currencies.",
  alternates: { canonical: "/markets" },
};

// The market tables are client components that stream /api/market/*.
// This page itself is static shell + SEO.
export const dynamic = "force-static";

export default function MarketsPage() {
  return (
    <div className="container space-y-8 py-8">
      <JsonLd data={breadcrumbLd([{ name: "Home", url: "/" }, { name: "Markets", url: "/markets" }])} />

      <header className="space-y-2">
        <h1 className="font-heading text-3xl font-bold">Global Markets</h1>
        <p className="max-w-2xl text-muted-foreground">
          World indices, cryptocurrencies, commodities and currencies at a glance.
          Prices and moves only — nothing here is investment advice.
        </p>
      </header>

      <div className="grid gap-8 lg:grid-cols-2">
        <MarketTable endpoint="/api/market/indices" title="World Indices" />
        <MarketTable endpoint="/api/market/crypto" title="Cryptocurrencies" limit={12} />
        <MarketTable endpoint="/api/market/commodities" title="Commodities" />
        <MarketTable endpoint="/api/market/forex" title="Currencies" />
      </div>

      <p className="text-xs text-muted-foreground">
        Data freshness is shown on each table. Delayed or demo data is labelled as
        such and must never be read as a real-time trading price.
      </p>
    </div>
  );
}
