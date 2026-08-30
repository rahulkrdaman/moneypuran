// U.S. Bureau of Labor Statistics — the market-moving macro releases:
// the Employment Situation (jobs report), CPI (inflation) and PPI.
// Public-domain U.S. government content. Each Atom entry carries a short factual
// summary; we also fetch the full release page for the detail.
import { parseFeed, htmlToText } from "../lib/rss.mjs";

const UA = { "User-Agent": "MoneyPuran newsroom/1.0 (corrections@moneypuran.com)" };

const FEEDS = [
  { url: "https://www.bls.gov/feed/empsit.rss", label: "Employment Situation" },
  { url: "https://www.bls.gov/feed/cpi.rss",    label: "Consumer Price Index" },
  { url: "https://www.bls.gov/feed/ppi.rss",    label: "Producer Price Index" },
];

export const bls = {
  name: "U.S. Bureau of Labor Statistics (BLS)",
  id: "bls",

  async list() {
    const out = [];
    for (const feed of FEEDS) {
      try {
        const xml = await (await fetch(feed.url, { headers: UA })).text();
        const items = parseFeed(xml);
        // Only the newest entry per feed — older ones are last month's data.
        if (items[0]) {
          const it = items[0];
          out.push({
            id: `bls:${it.guid || it.link}`,
            title: `${feed.label}: ${it.title}`,
            url: it.link,
            description: it.description,
            date: it.pubDate,
          });
        }
      } catch (e) { console.error("BLS feed error:", e.message); }
    }
    return out;
  },

  async fetchOne(item) {
    let text = htmlToText(item.description || "");
    if (item.url) {
      try {
        const html = await (await fetch(item.url, { headers: UA })).text();
        const m = html.match(/<(?:div|section)[^>]*id=["'](?:bodytext|main-content|content)["'][^>]*>([\s\S]*?)<\/(?:div|section)>/i);
        const seg = (m ? m[1] : html).replace(/<(nav|header|footer|aside|table)[\s\S]*?<\/\1>/gi, " ");
        const pageText = htmlToText(seg).slice(0, 13000);
        if (pageText.length > text.length) text = `${text}\n\n${pageText}`.trim();
      } catch { /* keep the summary */ }
    }
    return { sourceName: this.name, url: item.url, title: item.title, date: (item.date || "").trim(), text, image: "" };
  },
};
