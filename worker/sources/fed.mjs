// U.S. Federal Reserve Board — all press releases (monetary policy, enforcement,
// bank supervision, payments). Public-domain U.S. government content.
import { parseFeed, htmlToText, extractArticle } from "../lib/rss.mjs";

const UA = { "User-Agent": "MoneyPuran newsroom/1.0 (corrections@moneypuran.com)" };
const FEEDS = ["https://www.federalreserve.gov/feeds/press_all.xml"];

// Routine administrative notices with little market relevance. The FOMC
// statement, minutes, and monetary-policy items are always kept.
const SKIP = /^(?:Federal Reserve Board (?:announces (?:the appointment|approval of (?:the )?application)|will observe)|Minutes of the Board's discount rate|Agencies (?:issue|announce)|Federal Reserve Board and|.*technical (?:note|correction))/i;

const CONTENT_HINTS = [
  /<div[^>]*id=["']article["'][^>]*>/i,
  /<div[^>]*id=["']content["'][^>]*>/i,
  /<div[^>]*class=["'][^"']*col-(?:md|sm)-8[^"']*["'][^>]*>/i,
];

export const fed = {
  name: "U.S. Federal Reserve Board",
  id: "fed",

  async list() {
    const out = [];
    for (const feed of FEEDS) {
      try {
        const xml = await (await fetch(feed, { headers: UA })).text();
        for (const it of parseFeed(xml)) {
          if (!it.link || SKIP.test(it.title.trim())) continue;
          out.push({ id: `fed:${it.link}`, title: it.title.trim(), url: it.link, description: it.description, date: it.pubDate });
        }
      } catch (e) { console.error("Fed feed error:", e.message); }
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
