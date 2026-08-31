import { jsonLdScript } from "@/lib/seo/jsonld";

/** Server component. Renders one or more JSON-LD graphs. */
export function JsonLd({ data }: { data: unknown | unknown[] }) {
  return (
    <script
      type="application/ld+json"
      // content is our own serialized objects, not user input
      dangerouslySetInnerHTML={{ __html: jsonLdScript(data) }}
    />
  );
}
