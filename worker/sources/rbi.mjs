// Reserve Bank of India — official press releases (monetary policy, banking,
// forex, regulation). Government content, free to report with attribution.
import { parseFeed, htmlToText } from "../lib/rss.mjs";

const FEEDS = ["https://www.rbi.org.in/pressreleases_rss.xml"];
const UA = { "User-Agent": "Mozilla/5.0 (compatible; moneypuran-newsdesk/1.0)" };

// Skip the purely mechanical daily money-market / auction notices — not news.
const SKIP = /(Money Market Operations|VRRR|VRR auction|Reverse Repo|Repo.*auction|Underwriting Auction|auction (?:result|held|conducted)|Treasury Bill|Sale of.*Government of India|Government Stock|State Government|Overnight|91 Days|182 Days|364 Days|Premature redemption.*Sovereign Gold Bond|Scheduled Bank.*Statement|SDF|MSF Facility)/i;

export const rbi = {
  name: "Reserve Bank of India (RBI)",
  id: "rbi",

  async list() {
    const out = [];
    for (const feed of FEEDS) {
      try {
        const xml = await (await fetch(feed, { headers: UA })).text();
        for (const it of parseFeed(xml)) {
          if (SKIP.test(it.title)) continue;
          out.push({ id: `rbi:${it.link}`, title: it.title, url: it.link, description: it.description, date: it.pubDate });
        }
      } catch (e) { console.error("RBI feed error:", e.message); }
    }
    return out;
  },

  async fetchOne(item) {
    let text = htmlToText(item.description || "");
    if (text.length < 400) {
      try {
        const html = await (await fetch(item.url, { headers: UA })).text();
        const m = html.match(/<div[^>]*id=["']pageContent["'][^>]*>([\s\S]*?)<\/div>\s*<\/div>/i);
        text = htmlToText(m ? m[1] : html).slice(0, 12000);
      } catch { /* keep the description */ }
    }
    return { sourceName: this.name, url: item.url, title: item.title, date: (item.date || "").trim(), text, image: "" };
  },
};
