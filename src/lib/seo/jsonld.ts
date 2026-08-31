/**
 * Structured-data builders. Rule (spec §23): emit ONLY fields we actually have —
 * never fabricate ratings, prices, FAQ entries, or dates. Each builder returns a
 * plain object; render with <script type="application/ld+json">.
 */

const SITE = process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com";
const NAME = "MoneyPuran";

export function organizationLd() {
  return {
    "@context": "https://schema.org",
    "@type": "NewsMediaOrganization",
    "@id": `${SITE}/#organization`,
    name: NAME,
    url: SITE,
    logo: { "@type": "ImageObject", url: `${SITE}/logo.png` },
    slogan: "Global Financial Intelligence",
    sameAs: [
      "https://twitter.com/moneypuran",
      "https://www.facebook.com/moneypuran",
      "https://www.linkedin.com/company/moneypuran",
      "https://t.me/moneypuran",
      "https://www.youtube.com/@moneypuran",
    ],
  };
}

export function websiteLd() {
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "@id": `${SITE}/#website`,
    url: SITE,
    name: NAME,
    publisher: { "@id": `${SITE}/#organization` },
    inLanguage: "en",
    potentialAction: {
      "@type": "SearchAction",
      target: { "@type": "EntryPoint", urlTemplate: `${SITE}/search?q={search_term_string}` },
      "query-input": "required name=search_term_string",
    },
  };
}

export function breadcrumbLd(items: Array<{ name: string; url: string }>) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map((it, i) => ({
      "@type": "ListItem",
      position: i + 1,
      name: it.name,
      item: it.url.startsWith("http") ? it.url : `${SITE}${it.url}`,
    })),
  };
}

export interface ArticleLdInput {
  url: string;
  headline: string;
  description?: string | null;
  image?: string | null;
  datePublished?: string | Date | null;
  dateModified?: string | Date | null;
  authorName?: string | null;
  authorUrl?: string | null;
  section?: string | null;
  isNews?: boolean;
}

export function articleLd(a: ArticleLdInput) {
  const iso = (d?: string | Date | null) => (d ? new Date(d).toISOString() : undefined);
  const out: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": a.isNews ? "NewsArticle" : "Article",
    "@id": `${a.url}#article`,
    mainEntityOfPage: a.url,
    headline: a.headline.slice(0, 110),
    publisher: { "@id": `${SITE}/#organization` },
  };
  if (a.description) out.description = a.description;
  if (a.image) out.image = [a.image];
  if (iso(a.datePublished)) out.datePublished = iso(a.datePublished);
  if (iso(a.dateModified) || iso(a.datePublished)) out.dateModified = iso(a.dateModified) ?? iso(a.datePublished);
  if (a.authorName) out.author = { "@type": "Person", name: a.authorName, ...(a.authorUrl ? { url: a.authorUrl } : {}) };
  if (a.section) out.articleSection = a.section;
  return out;
}

/**
 * A market entity page (stock / index / crypto / commodity / fx).
 * We do NOT emit `Product`/`offers` with a price — a live quote is not a
 * purchasable offer and stamping it as one is misleading structured data.
 * `Dataset` + `FinancialProduct` (name/description only) is the honest choice.
 */
export function marketEntityLd(input: {
  url: string;
  name: string;
  symbol: string;
  assetClass: string;
  description?: string | null;
}) {
  return {
    "@context": "https://schema.org",
    "@type": input.assetClass === "crypto" ? "Dataset" : "FinancialProduct",
    "@id": `${input.url}#entity`,
    name: `${input.name} (${input.symbol})`,
    url: input.url,
    ...(input.description ? { description: input.description } : {}),
    provider: { "@id": `${SITE}/#organization` },
  };
}

/** Only call this when a *visible* FAQ block is rendered on the page (spec §23). */
export function faqLd(qas: Array<{ q: string; a: string }>) {
  if (!qas.length) return null;
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: qas.map(({ q, a }) => ({
      "@type": "Question",
      name: q,
      acceptedAnswer: { "@type": "Answer", text: a },
    })),
  };
}

/** helper for React: <JsonLd data={...} /> */
export function jsonLdScript(data: unknown | unknown[]): string {
  return JSON.stringify(Array.isArray(data) ? data : [data]);
}
