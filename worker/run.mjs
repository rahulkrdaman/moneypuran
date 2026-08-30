#!/usr/bin/env node
// MoneyPuran News Desk — fetch official finance sources + live market data, write
// original English business/markets articles with an LLM, publish to WordPress.
// Runs on a schedule via GitHub Actions.

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { SOURCES } from "./sources/index.mjs";
import { store } from "./lib/store.mjs";
import { makeWP, pingIndexNow, commonsImage } from "./lib/wp.mjs";
import { writeArticle, estimateCostUSD } from "./lib/llm.mjs";
import { CATEGORY_IMAGE_QUERY } from "./lib/images.mjs";

const HERE = dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(readFileSync(join(HERE, "config.json"), "utf8"));
const DRY = process.argv.includes("--dry");

const PROVIDER = cfg.provider || "gemini";
const { GEMINI_API_KEY, ANTHROPIC_API_KEY, WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD } = process.env;
const LLM_KEY = PROVIDER === "anthropic" ? ANTHROPIC_API_KEY : GEMINI_API_KEY;

function slugify(s, maxTokens = 7) {
  const toks = String(s || "").trim().toLowerCase()
    .replace(/https?:\/\/\S+/g, "").replace(/['’"]/g, "")
    .normalize("NFKD").replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "")
    .split("-").filter(Boolean);
  const stop = new Set(["the", "a", "an", "of", "to", "in", "on", "for", "and", "as", "at", "by", "is"]);
  const seen = new Set(), out = [];
  for (const t of toks) {
    if (stop.has(t) && out.length) continue;
    if (!seen.has(t)) { seen.add(t); out.push(t); }
    if (out.length >= maxTokens) break;
  }
  while (out.join("-").length > 70 && out.length > 3) out.pop();
  return out.join("-");
}

// Build the slug from the focus keyword so the whole phrase lands in the URL,
// then add a couple of distinguishing words from the headline.
function makeSlug(rawSlug, keyword, headline) {
  const base = rawSlug && /[a-z]/i.test(rawSlug) ? rawSlug : `${keyword || ""} ${headline || ""}`;
  return slugify(base, 7) || slugify(headline, 7) || `story-${Date.now()}`;
}

function esc(s) {
  return String(s || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// a, src, plus { related:[{link,title}], category:{link,name}, image:{url} }
function buildContent(a, src, links = {}) {
  const fig = links.image?.url
    ? `<figure class="newsdesk-figure"><img src="${esc(links.image.url)}" alt="${esc(a.focus_keyword || a.headline)}" loading="lazy" /></figure>\n`
    : "";
  const dek = a.dek ? `<p class="newsdesk-dek"><strong>${esc(a.dek)}</strong></p>\n` : "";
  const kp = (a.key_points || []).map((p) => `<li>${esc(p)}</li>`).join("");
  const box = kp ? `<div class="newsdesk-keypoints"><p><strong>Key points</strong></p><ul>${kp}</ul></div>\n` : "";

  const items = [];
  if (links.category?.link) items.push(`<a href="${esc(links.category.link)}">More ${esc(links.category.name || a.category)}</a>`);
  for (const r of (links.related || [])) items.push(`<a href="${esc(r.link)}">${esc(r.title)}</a>`);
  const internal = items.length
    ? `\n<div class="newsdesk-related"><p><strong>Also read:</strong></p><ul>${items.map((i) => `<li>${i}</li>`).join("")}</ul></div>\n`
    : "";

  const foot =
    `\n<p class="newsdesk-source"><em>Based on information published by ${esc(src.sourceName)}. ` +
    (src.url && /^https?:\/\//.test(src.url)
      ? `Source: <a href="${esc(src.url)}" target="_blank" rel="noopener nofollow">${esc(src.sourceName)}</a>. `
      : "") +
    `Spotted an error? <a href="mailto:${esc(cfg.correctionsEmail)}">${esc(cfg.correctionsEmail)}</a></em></p>`;

  return fig + dek + box + (a.body_html || "") + internal + foot;
}

async function main() {
  console.log(`\n== MoneyPuran News Desk ${DRY ? "(DRY RUN)" : ""} ${new Date().toISOString()} ==`);
  console.log(`provider=${PROVIDER} model=${JSON.stringify(cfg.model)} mode=${cfg.mode} perRun=${cfg.perRunCap} dailyCap=${cfg.dailyCap} usedToday=${store.dailyCount()}`);

  let budget = Math.min(cfg.perRunCap, Math.max(0, cfg.dailyCap - store.dailyCount()));
  if (budget <= 0) { console.log("daily cap reached — nothing to do"); return; }

  let wp = null;
  if (!DRY) {
    if (!LLM_KEY) throw new Error(`${PROVIDER === "anthropic" ? "ANTHROPIC_API_KEY" : "GEMINI_API_KEY"} not set`);
    if (!WP_SITE_URL || !WP_USERNAME || !WP_APP_PASSWORD) throw new Error("WP_SITE_URL / WP_USERNAME / WP_APP_PASSWORD not set");
    wp = makeWP({ siteUrl: WP_SITE_URL, username: WP_USERNAME, appPassword: WP_APP_PASSWORD });
    const me = await wp.checkAuth();
    console.log(`WP auth OK: ${me.name} (${me.roles.join(",")})`);
  }

  const created = [];
  let skipped = 0, cost = 0;
  const doneTitles = [];
  const STOP = new Set(["the", "of", "on", "in", "and", "for", "to", "with", "a", "an", "as", "at", "by",
    "sec", "fed", "rbi", "sebi", "us", "india", "today", "market", "markets", "stock", "report", "data",
    "says", "said", "amid", "after", "over", "new", "million", "billion"]);

  const wordset = (title) => new Set(String(title).toLowerCase().replace(/[^a-z0-9 ]/g, " ")
    .split(/\s+/).filter((x) => x.length > 2 && !STOP.has(x)));

  const tooSimilar = (title) => {
    const w = wordset(title);
    if (w.size < 3) { doneTitles.push(w); return false; }
    for (const prev of doneTitles) {
      if (prev.size < 3) continue;
      const inter = [...w].filter((x) => prev.has(x)).length;
      if (inter >= Math.min(w.size, prev.size) * 0.8) return true;
    }
    doneTitles.push(w);
    return false;
  };

  if (!DRY && wp) {
    try {
      const recent = await wp.recentPublishedTitles(60);
      for (const t of recent) { const s = wordset(t); if (s.size >= 3) doneTitles.push(s); }
      console.log(`seeded dedup with ${recent.length} recent published titles`);
    } catch (e) { console.warn("could not seed recent titles:", e.message); }
  }

  for (const [key, sdef] of Object.entries(cfg.sources)) {
    if (!sdef.enabled || budget <= 0) continue;
    const source = SOURCES[key];
    if (!source) { console.warn(`unknown source '${key}'`); continue; }

    let items;
    try { items = await source.list(); }
    catch (e) { console.error(`[${key}] list failed: ${e.message}`); continue; }

    const fresh = items.filter((it) => !store.has(it.id));
    const sourceCap = Math.min(budget, sdef.perRunCap ?? budget);
    let sourceUsed = 0;
    console.log(`[${key}] ${items.length} in feed, ${fresh.length} new, cap ${sourceCap}`);

    for (const it of fresh) {
      if (budget <= 0 || sourceUsed >= sourceCap) break;
      let src;
      try { src = await source.fetchOne(it); }
      catch (e) { console.error(`[${key}] fetch ${it.id}: ${e.message}`); continue; }

      const minChars = key === "marketwrap" ? 120 : (cfg.minSourceChars || 500);
      if (!src.text || src.text.length < minChars) {
        store.add(it.id); skipped++;
        console.log(`  skip (thin: ${src.text?.length || 0} chars) ${it.title.slice(0, 60)}`);
        continue;
      }

      if (tooSimilar(it.title)) {
        store.add(it.id); skipped++;
        console.log(`  skip (near-duplicate this run) ${it.title.slice(0, 60)}`);
        continue;
      }

      if (DRY) {
        console.log(`  WOULD WRITE: "${it.title.slice(0, 70)}" (${src.text.length} chars)`);
        budget--; sourceUsed++; continue;
      }

      let result;
      try {
        result = await writeArticle({
          provider: PROVIDER, apiKey: LLM_KEY, model: cfg.model, effort: cfg.effort,
          source: src, allowedCategories: cfg.allowedCategories, publication: cfg.publication,
        });
      } catch (e) { console.error(`  LLM error: ${e.message}`); continue; }

      cost += estimateCostUSD(result);
      const a = result.article;
      store.add(it.id);

      if (!a.usable) { skipped++; console.log(`  reject: ${a.reject_reason || "not usable"}`); continue; }
      if (tooSimilar(a.headline)) { skipped++; console.log(`  skip (similar article exists) ${a.headline.slice(0, 60)}`); continue; }

      const kw = a.focus_keyword || "";
      const category = cfg.allowedCategories.includes(a.category) ? a.category : cfg.fallbackCategory;
      const catId = await wp.resolveCategory(category);
      const authorId = cfg.authorMap[category] || cfg.defaultAuthorId;

      let featured = 0;
      if (src.image) featured = await wp.sideloadFeatured(src.image, kw || a.headline);
      if (!featured) {
        for (const query of [a.image_query, ...(CATEGORY_IMAGE_QUERY[category] || [])].filter(Boolean)) {
          const alt = await commonsImage(query);
          if (!alt) continue;
          featured = await wp.sideloadFeatured(alt, kw || a.headline);
          if (featured && typeof featured === "object") break;
        }
      }
      const featuredId = featured && typeof featured === "object" ? featured.id : (featured || 0);
      const featuredUrl = featured && typeof featured === "object" ? featured.url : "";

      const [related, categoryLink] = await Promise.all([
        wp.recentInCategory(catId, 0, 2),
        wp.categoryLink(catId),
      ]);

      const post = {
        title: a.headline,
        slug: makeSlug(a.slug, kw, a.headline),
        content: buildContent(a, src, { related, category: categoryLink, image: { url: featuredUrl } }),
        excerpt: a.meta_description || a.dek || "",
        status: cfg.mode === "pending" ? "pending" : "publish",
        categories: catId ? [catId] : [],
        author: authorId,
        ...(featuredId ? { featured_media: featuredId } : {}),
      };

      try {
        const res = await wp.createPost(post);
        const rm = await wp.setRankMath(res.id, {
          focusKeyword: kw,
          title: a.seo_title || a.headline,
          description: a.meta_description || "",
        });
        created.push({ id: res.id, status: res.status, cat: category, link: res.link });
        store.bumpDaily();
        budget--; sourceUsed++;
        console.log(`  ${res.status.toUpperCase()} #${res.id} [${category}] kw="${kw}" rm=${rm ? "ok" : "skip"}  ${a.headline}`);
      } catch (e) { console.error(`  publish failed: ${e.message}`); }
    }
  }

  const publishedUrls = created.filter((c) => c.status === "publish" && c.link).map((c) => c.link);
  if (!DRY && publishedUrls.length && cfg.indexNowKey) {
    const host = new URL(WP_SITE_URL).host;
    const r = await pingIndexNow({ host, key: cfg.indexNowKey, urls: publishedUrls });
    console.log(`IndexNow: ${r.ok ? `submitted ${publishedUrls.length} URL(s)` : `failed (${r.status || r.error || "skipped"})`}`);
  }

  if (!DRY) store.flush();
  console.log(`\nsummary: created=${created.length} skipped=${skipped} est_cost=$${cost.toFixed(3)} dailyUsed=${store.dailyCount()}`);
  for (const c of created) console.log(`  ${c.status} ${c.link || c.id}`);
}

main().catch((e) => { console.error("FATAL:", e.message); process.exit(1); });
