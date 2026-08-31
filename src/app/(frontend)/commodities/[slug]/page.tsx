import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { symbolBySlug, COMMODITIES } from "@/lib/market";
import { EntityPage } from "@/components/market/EntityPage";

export const dynamicParams = true;
export const revalidate = 300;

export function generateStaticParams() {
  return COMMODITIES.map((s) => ({ slug: s.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const m = symbolBySlug("commodity", slug);
  if (!m) return { title: "Not found" };
  return {
    title: `${m.name} Price Today — Live Chart & News`,
    description: `Live ${m.name} price, chart, historical performance and the news moving ${m.name} on MoneyPuran.`,
    alternates: { canonical: `/commodities/${m.slug}` },
  };
}

export default async function CommodityPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const m = symbolBySlug("commodity", slug);
  if (!m) notFound();
  return <EntityPage meta={m} />;
}
