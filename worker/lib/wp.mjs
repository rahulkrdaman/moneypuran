// Dependency-free WordPress REST client.

// A real browser UA: Hostinger's edge (hcdn) bot-challenges datacenter IPs that
// send an unfamiliar User-Agent, returning a 403 HTML page instead of JSON.
const UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36";

export function makeWP({ siteUrl, username, appPassword }) {
  const base = siteUrl.replace(/\/+$/, "") + "/wp-json";
  const auth = "Basic " + Buffer.from(`${username}:${appPassword.replace(/\s+/g, "")}`).toString("base64");

  async function call(path, { method = "GET", body, query } = {}) {
    let url = path.startsWith("http") ? path : base + path;
    if (query) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) if (v != null && v !== "") qs.set(k, String(v));
      url += (url.includes("?") ? "&" : "?") + qs;
    }
    const headers = { Authorization: auth, Accept: "application/json", "User-Agent": UA };
    if (body !== undefined) headers["Content-Type"] = "application/json";
    const payload = body !== undefined ? JSON.stringify(body) : undefined;

    // Retry transient network failures + edge bot-challenges (a 403/HTML page,
    // or 429/502/503). CI runners cold-start slowly and Hostinger's edge
    // sometimes challenges the first requests from a datacenter IP.
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    let res, text, data, lastErr;
    for (let attempt = 1; attempt <= 5; attempt++) {
      try {
        res = await fetch(url, { method, headers, body: payload });
        text = await res.text();
        try { data = text ? JSON.parse(text) : null; } catch { data = text; }
        const edgeChallenge = res.status === 403 && typeof data === "string" && /<html|<!doctype/i.test(data);
        if ((edgeChallenge || [429, 502, 503, 520, 522].includes(res.status)) && attempt < 5) {
          await wait(attempt * 3000);
          continue;
        }
        break;
      } catch (e) {
        lastErr = e;
        if (attempt === 5) throw new Error(`WP ${method} ${path} -> network: ${e.message}${e.cause?.code ? ` (${e.cause.code})` : ""}`);
        await wait(attempt * 2500);
      }
    }
    if (!res) throw lastErr;
    if (!res.ok) {
      const detail = data && data.message ? data.message : String(data).replace(/\s+/g, " ").slice(0, 300);
      throw new Error(`WP ${method} ${path} -> ${res.status}: ${detail}`);
    }
    return { data, headers: res.headers };
  }

  let categoryCache = null;

  return {
    call,

    async checkAuth() {
      const { data } = await call("/wp/v2/users/me", { query: { context: "edit" } });
      return { id: data.id, name: data.name, roles: data.roles };
    },

    async resolveCategory(name) {
      if (!categoryCache) {
        categoryCache = new Map();
        for (let page = 1; page <= 5; page++) {
          const { data, headers } = await call("/wp/v2/categories", { query: { per_page: 100, page, _fields: "id,name,slug" } });
          for (const c of data) { categoryCache.set(c.name.trim(), c.id); categoryCache.set(c.slug, c.id); }
          if (page >= Number(headers.get("x-wp-totalpages") || 1)) break;
        }
      }
      if (categoryCache.has(name.trim())) return categoryCache.get(name.trim());
      const { data } = await call("/wp/v2/categories", { method: "POST", body: { name } });
      categoryCache.set(name.trim(), data.id);
      return data.id;
    },

    // Read pixel dimensions from the file header without an image library.
    _imgSize(buf) {
      try {
        if (buf[0] === 0x89 && buf[1] === 0x50) return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) };
        if (buf[0] === 0x47 && buf[1] === 0x49) return { w: buf.readUInt16LE(6), h: buf.readUInt16LE(8) };
        if (buf[0] === 0xff && buf[1] === 0xd8) {
          let o = 2;
          while (o + 9 < buf.length) {
            if (buf[o] !== 0xff) { o++; continue; }
            const m = buf[o + 1];
            if (m >= 0xc0 && m <= 0xcf && m !== 0xc4 && m !== 0xc8 && m !== 0xcc) {
              return { h: buf.readUInt16BE(o + 5), w: buf.readUInt16BE(o + 7) };
            }
            o += 2 + buf.readUInt16BE(o + 2);
          }
        }
      } catch { /* fall through */ }
      return null;
    },

    // Best-effort: fetch an image URL and attach it as the featured image.
    async sideloadFeatured(imageUrl, postTitle) {
      try {
        if (/(^|[\/_-])logo|emblem|placeholder|default|(^|[\/_-])icon|sprite/i.test(imageUrl)) return 0;
        const r = await fetch(imageUrl, { headers: { "User-Agent": UA } });
        if (!r.ok) return 0;
        const type = r.headers.get("content-type") || "image/jpeg";
        if (!/^image\//.test(type)) return 0;
        const buf = Buffer.from(await r.arrayBuffer());
        if (buf.length < 6000 || buf.length > 8_000_000) return 0;
        const dim = this._imgSize(buf);
        if (dim && (Math.max(dim.w, dim.h) < 640 || dim.w < 480)) return 0;
        const ext = type.includes("png") ? "png" : type.includes("webp") ? "webp" : "jpg";
        const fname = `newsdesk-${Date.now()}.${ext}`;
        const res = await fetch(base + "/wp/v2/media", {
          method: "POST",
          headers: {
            Authorization: auth,
            "User-Agent": UA,
            "Content-Type": type,
            "Content-Disposition": `attachment; filename="${fname}"`,
          },
          body: buf,
        });
        if (!res.ok) return 0;
        const media = await res.json();
        await call(`/wp/v2/media/${media.id}`, { method: "POST", body: { alt_text: postTitle, caption: "" } }).catch(() => {});
        return { id: media.id, url: media.source_url || "" };
      } catch { return 0; }
    },

    async createPost(post) {
      const { data } = await call("/wp/v2/posts", { method: "POST", body: post });
      return data;
    },

    // Set Rank Math focus keyword / SEO title / meta description via Rank Math's own
    // REST route (no extra plugin needed). Silently no-ops if Rank Math isn't installed.
    async setRankMath(postId, { focusKeyword, title, description }) {
      const meta = {};
      if (focusKeyword) meta.rank_math_focus_keyword = focusKeyword;
      if (title) meta.rank_math_title = title;
      if (description) meta.rank_math_description = description;
      if (!Object.keys(meta).length) return false;
      try {
        await call("/rankmath/v1/updateMeta", { method: "POST", body: { objectID: postId, objectType: "post", meta } });
        return true;
      } catch { return false; }
    },

    async recentInCategory(catId, excludeId, n = 2) {
      if (!catId) return [];
      try {
        const { data } = await call("/wp/v2/posts", {
          query: { categories: catId, per_page: n + 2, orderby: "date", order: "desc", _fields: "id,link,title", status: "publish" },
        });
        return data.filter((p) => p.id !== excludeId).slice(0, n)
          .map((p) => ({ link: p.link, title: (p.title.rendered || "").replace(/<[^>]+>/g, "") }));
      } catch { return []; }
    },

    async recentPublishedTitles(n = 60) {
      const { data } = await call("/wp/v2/posts", {
        query: { per_page: Math.min(n, 100), orderby: "date", order: "desc", status: "publish", _fields: "title", context: "edit" },
      });
      return data.map((p) => (p.title && (p.title.raw || p.title.rendered) || "").replace(/<[^>]+>/g, ""));
    },

    async categoryLink(catId) {
      if (!catId) return null;
      try {
        const { data } = await call(`/wp/v2/categories/${catId}`, { query: { _fields: "link,name" } });
        return data;
      } catch { return null; }
    },
  };
}

// Direct IndexNow ping (Bing, Yandex, Seznam, Naver, DuckDuckGo). Safety net in case
// the plugin's own publish hook doesn't fire for REST-created posts.
export async function pingIndexNow({ host, key, urls }) {
  if (!key || !urls?.length) return { ok: false, skipped: true };
  try {
    const res = await fetch("https://api.indexnow.org/indexnow", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ host, key, keyLocation: `https://${host}/${key}.txt`, urlList: urls }),
    });
    return { ok: res.ok, status: res.status };
  } catch (e) {
    return { ok: false, error: e.message };
  }
}

// Find a freely-licensed landscape photo on Wikimedia Commons for a plain-English
// query. Returns a full image URL (~1600px wide) or "".
export async function commonsImage(query) {
  const q = String(query || "").trim();
  if (!q) return "";
  try {
    const api =
      "https://commons.wikimedia.org/w/api.php?format=json&action=query&generator=search" +
      "&gsrnamespace=6&gsrlimit=15&prop=imageinfo&iiprop=url|size|mime" +
      "&gsrsearch=" + encodeURIComponent(q);
    const j = await (await fetch(api, { headers: { "User-Agent": UA } })).json();
    const pages = j?.query?.pages ? Object.values(j.query.pages) : [];
    const pick = pages
      .filter((p) => {
        const i = p.imageinfo && p.imageinfo[0];
        return i && /jpeg|png/.test(i.mime) && i.width >= 1200 && i.width >= i.height &&
          !/logo|icon|emblem|coat[-_ ]of[-_ ]arms|\bmap\b|diagram|\.svg/i.test(p.title);
      })
      .sort((a, b) => b.imageinfo[0].width - a.imageinfo[0].width)[0];
    if (!pick) return "";
    const file = pick.title.replace(/^File:/, "");
    return "https://commons.wikimedia.org/wiki/Special:FilePath/" + encodeURIComponent(file) + "?width=1600";
  } catch {
    return "";
  }
}
