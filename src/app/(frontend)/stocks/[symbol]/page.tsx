import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { resolveSymbol, STOCKS } from "@/lib/market";
import { EntityPage } from "@/components/market/EntityPage";

export const dynamicParams = true;
export const revalidate = 300;

export function generateStaticParams() {
  return STOCKS.map((s) => ({ symbol: s.slug }));
}

function meta(param: string) {
  return resolveSymbol(param, "stock") ?? resolveSymbol(param, "etf");
}

export async function generateMetadata({ params }: { params: Promise<{ symbol: string }> }): Promise<Metadata> {
  const { symbol } = await params;
  const m = meta(symbol);
  if (!m) return { title: "Not found" };
  return {
    title: `${m.name} (${m.symbol}) Stock Price & News`,
    description: `${m.name} (${m.symbol}) live stock price, chart, key statistics and latest news on MoneyPuran.`,
    alternates: { canonical: `/stocks/${m.slug}` },
  };
}

export default async function StockPage({ params }: { params: Promise<{ symbol: string }> }) {
  const { symbol } = await params;
  const m = meta(symbol);
  if (!m) notFound();
  return <EntityPage meta={m} />;
}
