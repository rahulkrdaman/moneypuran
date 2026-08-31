import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { symbolBySlug, CRYPTO } from "@/lib/market";
import { EntityPage } from "@/components/market/EntityPage";

export const dynamicParams = true;
export const revalidate = 120;

export function generateStaticParams() {
  return CRYPTO.map((s) => ({ slug: s.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const m = symbolBySlug("crypto", slug);
  if (!m) return { title: "Not found" };
  return {
    title: `${m.name} Price (${m.symbol}) — Live Chart & News`,
    description: `${m.name} (${m.symbol}) live price in USD, market cap, 24h volume, chart and latest crypto news on MoneyPuran.`,
    alternates: { canonical: `/crypto/${m.slug}` },
  };
}

export default async function CryptoPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const m = symbolBySlug("crypto", slug);
  if (!m) notFound();
  return <EntityPage meta={m} />;
}
