// U.S. Securities and Exchange Commission — official press releases.
// Public-domain U.S. government content, free to report with attribution.
import { parseFeed, htmlToText, extractArticle } from "../lib/rss.mjs";

// SEC asks automated clients to send a descriptive User-Agent with contact info.
const UA = { "User-Agent": "MoneyPuran newsroom/1.0 (corrections@moneypuran.com)" };
const FEEDS = ["https://www.sec.gov/news/pressreleases.rss"];

// Purely procedural / calendar items — not market news.
const SKIP = /(closed for the holiday|Sunshine Act Meeting|open meeting agenda|will host a|webcast will|personnel announcement|names? .* as (?:Director|Chief))/i;

const CONTENT_HINTS = [
  /<div[^>]*class=["'][^"']*(?:article-body|field--name-body|content-block|release-body)[^"']*["'][^>]*>/i,
  /<article[^>]*>/i,
  /<main[^>]*>/i,
];

export const sec = {
  name: "U.S. Securities and Exchange Commission (SEC)",
  id: "sec",

  async list() {
    const out = [];
    for (const feed of FEEDS) {
      try {
        const xml = await (await fetch(feed, { headers: UA })).text();
        for (const it of parseFeed(xml)) {
          const title = it.title.replace(/\s+/g, " ").trim();
          if (!it.link || SKIP.test(title)) continue;
          out.push({ id: `sec:${it.link}`, title, url: it.link, description: it.description, date: it.pubDate });
        }
      } catch (e) { console.error("SEC feed error:", e.message); }
    }
    return out;
  },

  async fetchOne(item) {
    let text = htmlToText(item.description || "");
    try {
      const html = await (await fetch(item.url, { headers: UA })).text();
      const pageText = extractArticle(html, CONTENT_HINTS);
      if (pageText.length > text.length) text = pageText;
    } catch { /* keep description */ }
    return { sourceName: this.name, url: item.url, title: item.title, date: (item.date || "").trim(), text, image: "" };
  },
};
