import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { symbolBySlug, INDICES } from "@/lib/market";
import { EntityPage } from "@/components/market/EntityPage";

export const dynamicParams = true;
export const revalidate = 120;

export function generateStaticParams() {
  return INDICES.map((s) => ({ slug: s.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const m = symbolBySlug("index", slug);
  if (!m) return { title: "Not found" };
  return {
    title: `${m.name} Today — Live Level, Chart & Constituents`,
    description: `${m.name} live level, intraday chart, performance and the news moving it on MoneyPuran.`,
    alternates: { canonical: `/markets/${m.slug}` },
  };
}

export default async function IndexPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const m = symbolBySlug("index", slug);
  if (!m) notFound();
  return <EntityPage meta={m} />;
}
