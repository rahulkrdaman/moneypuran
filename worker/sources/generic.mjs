// Generic RSS/Atom source. Handles two shapes:
//   - feeds whose <description>/<content> already carries usable body text
//   - feeds that give a headline + link only (set fetchPage:true)
import { parseFeed, htmlToText, extractArticle } from "../lib/rss.mjs";

const UA = { "User-Agent": "MoneyPuran newsroom/1.0 (+https://moneypuran.com; corrections@moneypuran.com)" };

export function makeGenericSource({ id, name, feeds, skip, keep, minLen = 400, fetchPage = false, contentHints = [] }) {
  const skipRe = skip ? new RegExp(skip, "i") : null;
  const keepRe = keep ? new RegExp(keep, "i") : null;
  return {
    id,
    name,
    async list() {
      const out = [];
      for (const feed of feeds) {
        try {
          const xml = await (await fetch(feed, { headers: UA })).text();
          for (const it of parseFeed(xml)) {
            if (!it.link) continue;
            if (skipRe && skipRe.test(it.title)) continue;
            if (keepRe && !keepRe.test(it.title + " " + (it.description || ""))) continue;
            out.push({ id: `${id}:${it.guid || it.link}`, title: it.title, url: it.link, description: it.description, date: it.pubDate });
          }
        } catch (e) { console.error(`${id} feed error:`, e.message); }
      }
      return out;
    },
    async fetchOne(item) {
      let text = htmlToText(item.description || "");
      if ((text.length < minLen || fetchPage) && item.url) {
        try {
          const html = await (await fetch(item.url, { headers: UA })).text();
          const pageText = extractArticle(html, contentHints);
          if (pageText.length > text.length) text = pageText;
        } catch { /* keep description */ }
      }
      return { sourceName: name, url: item.url, title: item.title, date: (item.date || "").trim(), text, image: "" };
    },
  };
}
