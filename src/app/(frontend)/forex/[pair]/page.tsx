import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { symbolBySlug, FOREX } from "@/lib/market";
import { EntityPage } from "@/components/market/EntityPage";

export const dynamicParams = true;
export const revalidate = 120;

export function generateStaticParams() {
  return FOREX.map((s) => ({ pair: s.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ pair: string }> }): Promise<Metadata> {
  const { pair } = await params;
  const m = symbolBySlug("forex", pair);
  if (!m) return { title: "Not found" };
  return {
    title: `${m.name} — Live Exchange Rate & Chart`,
    description: `${m.name} live exchange rate, chart, historical data and the central-bank context moving it on MoneyPuran.`,
    alternates: { canonical: `/forex/${m.slug}` },
  };
}

export default async function ForexPage({ params }: { params: Promise<{ pair: string }> }) {
  const { pair } = await params;
  const m = symbolBySlug("forex", pair);
  if (!m) notFound();
  return <EntityPage meta={m} />;
}
