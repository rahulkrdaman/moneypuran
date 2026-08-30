#!/usr/bin/env node
// MoneyPuran Evergreen Explainer Desk — publishes in-depth English "what is /
// how to" investing & personal-finance articles that answer questions US and
// Indian readers search every day. Works through worker/evergreen/questions.json
// in order and records progress in worker/state/evergreen-done.json.
//
// Runs on its own schedule, separate from the news desk.

import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { makeWP, pingIndexNow, commonsImage } from "./lib/wp.mjs";
import { writeEvergreen } from "./lib/evergreen-llm.mjs";
import { CATEGORY_IMAGE_QUERY } from "./lib/images.mjs";

const HERE = dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(readFileSync(join(HERE, "config.json"), "utf8"));
const QUESTIONS = JSON.parse(readFileSync(join(HERE, "evergreen", "questions.json"), "utf8")).questions;

const STATE_DIR = join(HERE, "state");
const DONE_FILE = join(STATE_DIR, "evergreen-done.json");
const loadDone = () => { try { return JSON.parse(readFileSync(DONE_FILE, "utf8")); } catch { return []; } };
const saveDone = (arr) => { mkdirSync(STATE_DIR, { recursive: true }); writeFileSync(DONE_FILE, JSON.stringify(arr, null, 1)); };

const DRY = process.argv.includes("--dry");
const PROVIDER = cfg.provider || "gemini";
const {
  GEMINI_API_KEY, ANTHROPIC_API_KEY,
  WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD,
  EVERGREEN_PER_RUN,
} = process.env;
const LLM_KEY = PROVIDER === "anthropic" ? ANTHROPIC_API_KEY : GEMINI_API_KEY;
const PER_RUN = Math.max(1, Math.min(6, Number(EVERGREEN_PER_RUN) || 3));

function slugify(s, maxTokens = 6) {
  const toks = String(s || "").trim().toLowerCase()
    .replace(/https?:\/\/\S+/g, "").replace(/['’"]/g, "")
    .normalize("NFKD").replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "")
    .split("-").filter(Boolean);
  const stop = new Set(["the", "a", "an", "of", "to", "in", "on", "for", "and", "is", "what", "how", "vs"]);
  const seen = new Set(), out = [];
  for (const t of toks) {
    if (stop.has(t) && out.length) continue;
    if (!seen.has(t)) { seen.add(t); out.push(t); }
    if (out.length >= maxTokens) break;
  }
  while (out.join("-").length > 65 && out.length > 3) out.pop();
  return out.join("-");
}

function esc(s) { return String(s || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }

function buildContent(a, q, { related = [], category = null, imageUrl = "", officialUrl = "" }) {
  const fig = imageUrl
    ? `<figure class="newsdesk-figure"><img src="${esc(imageUrl)}" alt="${esc(a.focus_keyword || a.headline)}" loading="lazy" /></figure>\n`
    : "";
  const dek = a.dek ? `<p class="newsdesk-dek"><strong>${esc(a.dek)}</strong></p>\n` : "";
  const kp = (a.key_points || []).map((p) => `<li>${esc(p)}</li>`).join("");
  const box = kp ? `<div class="newsdesk-keypoints"><p><strong>Key points</strong></p><ul>${kp}</ul></div>\n` : "";

  const items = [];
  if (category?.link) items.push(`<a href="${esc(category.link)}">More ${esc(category.name || q.category)}</a>`);
  for (const r of related) items.push(`<a href="${esc(r.link)}">${esc(r.title)}</a>`);
  const internal = items.length
    ? `\n<div class="newsdesk-related"><p><strong>Also read:</strong></p><ul>${items.map((i) => `<li>${i}</li>`).join("")}</ul></div>\n`
    : "";

  const off = officialUrl && /^https:\/\/[\w.-]+\.(gov|gov\.in|nic\.in|org\.in)(\/|$)/i.test(officialUrl)
    ? `\n<p class="newsdesk-source"><em>Official information: ` +
      `<a href="${esc(officialUrl)}" target="_blank" rel="noopener nofollow">${esc(officialUrl)}</a></em></p>`
    : "";
  const foot =
    `\n<p class="newsdesk-source"><em>This explainer is published by the MoneyPuran desk for general` +
    ` awareness. Rules, limits and rates change over time — please confirm with the official source.` +
    ` Corrections: <a href="mailto:${esc(cfg.correctionsEmail)}">${esc(cfg.correctionsEmail)}</a></em></p>`;

  return fig + dek + box + (a.body_html || "") + internal + off + foot;
}

function validate(a, q) {
  if (!a || typeof a !== "object") return "no object";
  const body = String(a.body_html || "");
  const words = body.replace(/<[^>]+>/g, " ").trim().split(/\s+/).length;
  if (words < 450) return `too short (${words} words)`;
  if (!/<h2/i.test(body)) return "no <h2> sections";
  if (!a.headline || !a.meta_description) return "missing headline/description";
  const kw = (q.keyword || "").toLowerCase();
  if (!body.toLowerCase().includes(kw) && !(a.headline || "").toLowerCase().includes(kw)) return "focus keyword not used";
  return null;
}

async function main() {
  console.log(`\n== MoneyPuran Evergreen Desk ${DRY ? "(DRY RUN)" : ""} ${new Date().toISOString()} ==`);
  const done = new Set(loadDone());
  const queue = QUESTIONS.filter((q) => !done.has(q.id));
  console.log(`provider=${PROVIDER} model=${JSON.stringify(cfg.model)} perRun=${PER_RUN} done=${done.size}/${QUESTIONS.length} remaining=${queue.length}`);
  if (!queue.length) { console.log("queue empty — all evergreen questions published."); return; }

  let wp = null;
  if (!DRY) {
    if (!LLM_KEY) throw new Error(`${PROVIDER === "anthropic" ? "ANTHROPIC_API_KEY" : "GEMINI_API_KEY"} not set`);
    if (!WP_SITE_URL || !WP_USERNAME || !WP_APP_PASSWORD) throw new Error("WP_SITE_URL / WP_USERNAME / WP_APP_PASSWORD not set");
    wp = makeWP({ siteUrl: WP_SITE_URL, username: WP_USERNAME, appPassword: WP_APP_PASSWORD });
    const me = await wp.checkAuth();
    console.log(`WP auth OK: ${me.name} (${me.roles.join(",")})`);
  }

  const STOP = new Set(["what", "how", "the", "and", "for", "with", "your", "vs", "is", "are", "does",
    "a", "an", "of", "to", "in", "on", "explained", "guide"]);
  const wordset = (s) => new Set(String(s).toLowerCase().replace(/[^a-z0-9 ]/g, " ")
    .split(/\s+/).filter((x) => x.length > 2 && !STOP.has(x)));
  const existing = [];
  if (!DRY && wp) {
    try {
      const titles = await wp.recentPublishedTitles(80);
      for (const t of titles) { const s = wordset(t); if (s.size >= 2) existing.push(s); }
      console.log(`seeded dedup with ${titles.length} recent titles`);
    } catch (e) { console.warn(`dedup seed failed: ${e.message}`); }
  }
  const isDuplicate = (title, kw) => {
    const w = wordset(title + " " + kw);
    if (w.size < 2) return false;
    for (const prev of existing) {
      const inter = [...w].filter((x) => prev.has(x)).length;
      if (inter >= Math.min(w.size, prev.size) * 0.85) return true;
    }
    return false;
  };

  const created = [];
  const batch = queue.slice(0, PER_RUN);

  for (const q of batch) {
    console.log(`\n[${q.id}] "${q.q}"  kw="${q.keyword}" cat=${q.category}`);
    if (DRY) { console.log("  WOULD WRITE"); continue; }

    if (isDuplicate(q.q, q.keyword)) {
      done.add(q.id); saveDone([...done]);
      console.log("  skip (a very similar post already exists) — marked done");
      continue;
    }

    let result;
    try {
      result = await writeEvergreen({
        provider: PROVIDER, apiKey: LLM_KEY, model: cfg.model, effort: cfg.effort,
        question: q.q, brief: q.brief, keyword: q.keyword, category: q.category,
        publication: cfg.publication,
      });
    } catch (e) { console.error(`  LLM error: ${e.message}`); continue; }

    const a = result.article;
    a.focus_keyword = q.keyword;
    a.category = q.category;
    const bad = validate(a, q);
    if (bad) { console.error(`  reject: ${bad}`); continue; }

    if (isDuplicate(a.headline, q.keyword)) {
      done.add(q.id); saveDone([...done]);
      console.log(`  skip (generated headline duplicates an existing post): ${a.headline}`);
      continue;
    }

    const catId = await wp.resolveCategory(q.category);
    const authorId = cfg.authorMap[q.category] || cfg.defaultAuthorId;

    let featuredId = 0, featuredUrl = "";
    const imgTries = [a.image_query, ...(CATEGORY_IMAGE_QUERY[q.category] || [])].filter(Boolean);
    for (const query of imgTries) {
      try {
        const img = await commonsImage(query);
        if (!img) continue;
        const sl = await wp.sideloadFeatured(img, q.keyword);
        if (sl && typeof sl === "object") { featuredId = sl.id; featuredUrl = sl.url; break; }
      } catch (e) { console.warn(`  image "${query}" failed: ${e.message}`); }
    }
    if (!featuredId) console.warn(`  no image found (tried: ${imgTries.join(" | ")})`);

    const [related, category] = await Promise.all([
      wp.recentInCategory(catId, 0, 2),
      wp.categoryLink(catId),
    ]);

    const post = {
      title: a.headline,
      slug: slugify(a.slug || q.keyword, 6),
      content: buildContent(a, q, { related, category, imageUrl: featuredUrl, officialUrl: a.official_url }),
      excerpt: a.meta_description || a.dek || "",
      status: "publish",
      categories: catId ? [catId] : [],
      author: authorId,
      ...(featuredId ? { featured_media: featuredId } : {}),
    };

    try {
      const res = await wp.createPost(post);
      const rm = await wp.setRankMath(res.id, {
        focusKeyword: q.keyword,
        title: a.seo_title || a.headline,
        description: a.meta_description || "",
      });
      created.push({ id: res.id, link: res.link, cat: q.category });
      existing.push(wordset(a.headline + " " + q.keyword));
      done.add(q.id);
      saveDone([...done]);
      console.log(`  PUBLISHED #${res.id} [${q.category}] author=${authorId} img=${featuredId || "none"} rm=${rm ? "ok" : "skip"}  ${a.headline}`);
    } catch (e) { console.error(`  publish failed: ${e.message}`); }
  }

  const urls = created.map((c) => c.link).filter(Boolean);
  if (!DRY && urls.length && cfg.indexNowKey) {
    const host = new URL(WP_SITE_URL).host;
    const r = await pingIndexNow({ host, key: cfg.indexNowKey, urls });
    console.log(`\nIndexNow: ${r.ok ? `submitted ${urls.length}` : `failed (${r.status || r.error || "skipped"})`}`);
  }

  console.log(`\nsummary: created=${created.length} done=${done.size}/${QUESTIONS.length}`);
  for (const c of created) console.log(`  ${c.link || c.id}`);
}

main().catch((e) => { console.error("FATAL:", e.message); process.exit(1); });
