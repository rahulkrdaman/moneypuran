// Minimal, dependency-free RSS/Atom parser. Good enough for government + regulator feeds.

function decode(s = "") {
  return s
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, "$1")
    .replace(/&lt;/g, "<").replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'")
    .replace(/&#8377;/g, "₹").replace(/&#36;/g, "$")
    .replace(/&amp;/g, "&")
    .trim();
}

function tag(block, name) {
  const m = block.match(new RegExp(`<${name}[^>]*>([\\s\\S]*?)<\\/${name}>`, "i"));
  return m ? decode(m[1]) : "";
}

export function parseFeed(xml) {
  const items = [];
  const blocks = xml.match(/<item[\s>][\s\S]*?<\/item>/gi) || xml.match(/<entry[\s>][\s\S]*?<\/entry>/gi) || [];
  for (const b of blocks) {
    let link = tag(b, "link");
    if (!link) {
      const m = b.match(/<link[^>]*href=["']([^"']+)["']/i);
      if (m) link = m[1];
    }
    items.push({
      title: tag(b, "title"),
      link: link.trim(),
      description: tag(b, "description") || tag(b, "summary") || tag(b, "content"),
      pubDate: tag(b, "pubDate") || tag(b, "published") || tag(b, "updated") || tag(b, "dc:date"),
      guid: tag(b, "guid") || link.trim(),
    });
  }
  return items;
}

// Pull the main article text out of a press-release page. `hints` is an ordered
// list of regexes that match the opening tag of the content container; the first
// one that yields a substantial block of text wins. Falls back to the whole
// page (chrome stripped) so a layout change degrades instead of breaking.
export function extractArticle(html = "", hints = []) {
  const stripped = String(html)
    .replace(/<(script|style|nav|header|footer|aside|form|svg)[\s\S]*?<\/\1>/gi, " ")
    .replace(/<!--[\s\S]*?-->/g, " ");
  for (const re of hints) {
    const m = stripped.match(re);
    if (!m) continue;
    const from = m.index + m[0].length;
    const txt = htmlToText(stripped.slice(from, from + 80000));
    if (txt.length > 300) return txt.slice(0, 13000);
  }
  return htmlToText(stripped).slice(0, 13000);
}

export function htmlToText(html = "") {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<\/(p|div|br|li|h[1-6]|tr)>/gi, "\n")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&#8377;/g, "₹")
    .replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"').replace(/&#0?39;/g, "'")
    .replace(/[ \t]+/g, " ")
    .replace(/\n\s*\n\s*\n+/g, "\n\n")
    .trim();
}
