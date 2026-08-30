// Provider-agnostic article writer for MoneyPuran — a business & markets news site.
// Supports Gemini (free tier) and Anthropic. Dependency-free (raw HTTPS).

const FIELDS = ["usable", "reject_reason", "focus_keyword", "headline", "seo_title", "slug",
  "meta_description", "dek", "key_points", "body_html", "category", "tags", "image_query", "confidence"];

function systemPrompt(publication, allowedCategories) {
  return [
    `You are a senior markets and business news editor for "${publication}", an English-language`,
    `business, markets and personal-finance news site read mainly by investors in the United States,`,
    `with a strong secondary audience in India.`,
    `You write ORIGINAL, search-optimised English news reports from official source material.`,
    ``,
    `SCOPE — HARD RULE:`,
    `- Publish ONLY stories about: stock markets, indices, individual companies and their shares,`,
    `  earnings, IPOs and listings, mergers/acquisitions, bonds and interest rates, central banks`,
    `  and monetary policy (Federal Reserve, RBI, ECB, etc.), macro data (inflation, jobs, GDP),`,
    `  financial regulation and enforcement (SEC, SEBI), crypto assets and crypto markets,`,
    `  commodities (gold, oil, metals, agri) and currencies, funds/ETFs, and practical personal`,
    `  finance / investing knowledge.`,
    `- If the source is NOT about business, markets, companies, the economy or finance`,
    `  (e.g. general politics, sport, entertainment, crime, weather, defence, health), set`,
    `  usable=false with a short reject_reason. When in doubt, reject.`,
    ``,
    `ACCURACY (non-negotiable):`,
    `- Use ONLY facts present in the source. Never invent quotes, numbers, dates, tickers, names or events.`,
    `- Do NOT copy the source's sentences or structure. Re-report in your own words, wire-service style.`,
    `- Attribute clearly: name the issuing body ("the SEC said", "according to the Federal Reserve",`,
    `  "BLS data showed", "RBI announced").`,
    `- If the SOURCE is a MARKET-DATA SUMMARY, write a factual daily market recap: which indices or`,
    `  assets moved and by how much, in plain language. You may add brief, widely-known and clearly`,
    `  hedged context (e.g. "ahead of next week's Fed meeting"); NEVER invent specific figures,`,
    `  analyst quotes, or causes that the data does not support. No price targets or predictions.`,
    `- Neutral, factual tone. NOTHING in the article may read as a recommendation to buy or sell.`,
    `- If the source is a trivial notice, routine auction, photo caption or has no real news, set usable=false.`,
    ``,
    `SEO REQUIREMENTS — follow all of these:`,
    `- focus_keyword: a SHORT English search phrase of 2-4 words. Natural, specific, the phrase a`,
    `  reader would actually type (e.g. "Fed rate decision", "SEC crypto charges", "Sensex today",`,
    `  "US jobs report", "gold price"). Short enough to sit inside the URL slug.`,
    `- seo_title: <= 60 characters. MUST BEGIN with the focus_keyword. Include a number or the year`,
    `  (e.g. 2026) somewhere in the title. Do not append the site name.`,
    `- headline (the on-page H1): <= 75 chars, contains the focus_keyword, reads naturally.`,
    `- meta_description: 120-155 chars, contains the focus_keyword once, ends with a reason to read.`,
    `- slug: lowercase, the focus_keyword words plus 1-2 more only, hyphen-separated, ASCII.`,
    `- body_html: clean HTML (<p>, <h2>, <ul><li>). MANDATORY 650-950 words — never fewer than 650.`,
    `    * the FIRST sentence must contain the focus_keyword`,
    `    * at least TWO <h2> subheadings must contain the focus_keyword`,
    `    * use the focus_keyword 6-10 times total across the article, naturally`,
    `    * 4-6 <h2> sections; short paragraphs (2-4 sentences); one <ul> of specifics (numbers, dates)`,
    `    * include ONE short FAQ section near the end: an <h2> "Frequently asked questions" with 2-3`,
    `      question/answer pairs as <p><strong>Question?</strong> Answer.</p>`,
    `    * to reach the word count, ADD genuine background/explainer paragraphs from general knowledge`,
    `      (what the body/rule/programme is, why it matters for markets, what typically happens next,`,
    `      how it affects ordinary investors) — but NEVER invent specific facts, figures or quotes`,
    `      that are not in the source`,
    `    * the FINAL <p> must be exactly: <p><em>This article is for information only and is not`,
    `      investment advice. Do your own research or consult a licensed adviser before investing.</em></p>`,
    `- key_points: 3-5 English bullets of the most important specifics (numbers, dates, names, tickers).`,
    `- category: exactly one of: ${allowedCategories.join(", ")}`,
    `- tags: 3-5 short English tags (company names, tickers, themes).`,
    `- image_query: 2-4 ENGLISH words for a stock-photo search that best illustrates this story`,
    `  (a place, building, object or activity — NOT a person's name unless world-famous).`,
    `  Examples: "New York Stock Exchange building", "Federal Reserve building", "gold bars",`,
    `  "oil drilling rig", "Bombay Stock Exchange building", "Bitcoin physical coin". Concrete and photographable.`,
    `- confidence: 0-1.`,
  ].join("\n");
}

const JSON_SHAPE = `Return ONLY a JSON object (no markdown fence):
{"usable":bool,"reject_reason":str,"focus_keyword":str,"headline":str,"seo_title":str,"slug":str,
"meta_description":str,"dek":str,"key_points":[str],"body_html":str,"category":str,"tags":[str],"image_query":str,"confidence":number}`;

function userPrompt(source, withShape) {
  return [
    `SOURCE: ${source.sourceName}`,
    `SOURCE URL: ${source.url}`,
    `SOURCE DATE: ${source.date || "unknown"}`,
    `SOURCE TITLE: ${source.title}`,
    ``,
    `SOURCE TEXT:`,
    source.text.slice(0, 14000),
    ...(withShape ? ["", JSON_SHAPE] : []),
  ].join("\n");
}

function parseArticle(text) {
  let t = String(text).trim().replace(/^```(?:json)?\s*/i, "").replace(/\s*```$/i, "");
  const a = t.indexOf("{"), b = t.lastIndexOf("}");
  if (a >= 0 && b > a) t = t.slice(a, b + 1);
  return JSON.parse(t);
}

/* --------------------------------- Gemini --------------------------------- */

const GEMINI_SCHEMA = {
  type: "OBJECT",
  properties: {
    usable: { type: "BOOLEAN" },
    reject_reason: { type: "STRING" },
    focus_keyword: { type: "STRING" },
    headline: { type: "STRING" },
    seo_title: { type: "STRING" },
    slug: { type: "STRING" },
    meta_description: { type: "STRING" },
    dek: { type: "STRING" },
    key_points: { type: "ARRAY", items: { type: "STRING" } },
    body_html: { type: "STRING" },
    category: { type: "STRING" },
    tags: { type: "ARRAY", items: { type: "STRING" } },
    image_query: { type: "STRING" },
    confidence: { type: "NUMBER" },
  },
  required: ["usable", "focus_keyword", "headline", "seo_title", "slug", "meta_description", "dek", "key_points", "body_html", "category", "image_query", "confidence"],
  propertyOrdering: FIELDS,
};

async function geminiCall({ apiKey, model, source, allowedCategories, publication, useSchema }) {
  const url = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent`;
  const generationConfig = { temperature: 0.4, maxOutputTokens: 9000, responseMimeType: "application/json" };
  if (useSchema) generationConfig.responseSchema = GEMINI_SCHEMA;
  const body = {
    system_instruction: { parts: [{ text: systemPrompt(publication, allowedCategories) }] },
    contents: [{ role: "user", parts: [{ text: userPrompt(source, !useSchema) }] }],
    generationConfig,
  };
  const res = await fetch(url, {
    method: "POST",
    headers: { "x-goog-api-key": apiKey, "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) {
    const msg = data?.error?.message || JSON.stringify(data).slice(0, 300);
    const err = new Error(`Gemini ${res.status}: ${msg}`);
    err.status = res.status;
    err.schemaRejected = /responseSchema|response_schema|Invalid JSON payload|Unknown name/i.test(msg);
    throw err;
  }
  if (data.promptFeedback?.blockReason) throw new Error(`Gemini blocked prompt: ${data.promptFeedback.blockReason}`);
  const cand = data.candidates?.[0];
  const fr = cand?.finishReason;
  if (fr && !["STOP", "MAX_TOKENS"].includes(fr)) throw new Error(`Gemini finishReason=${fr}`);
  const text = (cand?.content?.parts || [])
    .filter((p) => !p.thought && typeof p.text === "string")
    .map((p) => p.text).join("");
  if (!text) throw new Error("Gemini returned no text");
  return { article: parseArticle(text), usage: data.usageMetadata, model, provider: "gemini" };
}

async function geminiOne(opts) {
  try {
    return await geminiCall({ ...opts, useSchema: true });
  } catch (e) {
    if (e.schemaRejected || /valid JSON/.test(e.message)) return geminiCall({ ...opts, useSchema: false });
    throw e;
  }
}

async function geminiWrite(opts) {
  const models = Array.isArray(opts.model) ? opts.model : [opts.model];
  let lastErr;
  for (const model of models) {
    try {
      return await geminiOne({ ...opts, model });
    } catch (e) {
      lastErr = e;
      const retryable = e.status === 429 || e.status === 503 || /quota|high demand|overloaded/i.test(e.message);
      if (!retryable) throw e;
    }
  }
  throw lastErr;
}

/* -------------------------------- Anthropic ------------------------------- */

const ANTHROPIC_SCHEMA = {
  type: "object",
  additionalProperties: false,
  required: ["usable", "focus_keyword", "headline", "seo_title", "slug", "meta_description", "dek", "key_points", "body_html", "category", "tags"],
  properties: {
    usable: { type: "boolean" }, reject_reason: { type: "string" },
    focus_keyword: { type: "string" }, headline: { type: "string" }, seo_title: { type: "string" },
    slug: { type: "string" }, meta_description: { type: "string" }, dek: { type: "string" },
    key_points: { type: "array", items: { type: "string" } }, body_html: { type: "string" },
    category: { type: "string" }, tags: { type: "array", items: { type: "string" } },
    image_query: { type: "string" }, confidence: { type: "number" },
  },
};

async function anthropicWrite({ apiKey, model, effort, source, allowedCategories, publication }) {
  const res = await fetch("https://api.anthropic.com/v1/messages", {
    method: "POST",
    headers: { "x-api-key": apiKey, "anthropic-version": "2023-06-01", "content-type": "application/json" },
    body: JSON.stringify({
      model, max_tokens: 9000,
      system: systemPrompt(publication, allowedCategories),
      thinking: { type: "adaptive" },
      output_config: { effort: effort || "medium", format: { type: "json_schema", schema: ANTHROPIC_SCHEMA } },
      messages: [{ role: "user", content: userPrompt(source) }],
    }),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(`Anthropic ${res.status}: ${data?.error?.message || JSON.stringify(data).slice(0, 300)}`);
  if (data.stop_reason === "refusal") throw new Error(`Claude refused: ${data.stop_details?.category || "unknown"}`);
  const text = (data.content || []).find((b) => b.type === "text")?.text;
  if (!text) throw new Error("Claude returned no text block");
  return { article: parseArticle(text), usage: data.usage, model: data.model, provider: "anthropic" };
}

/* --------------------------------- API ---------------------------------- */

export async function writeArticle(opts) {
  if (opts.provider === "anthropic") return anthropicWrite(opts);
  return geminiWrite(opts);
}

export function estimateCostUSD({ provider, usage, model }) {
  if (provider !== "anthropic") return 0;
  const rates = { "claude-opus-5": [5, 25], "claude-opus-4-8": [5, 25], "claude-sonnet-5": [2, 10], "claude-haiku-4-5": [1, 5] };
  const key = Object.keys(rates).find((k) => (model || "").startsWith(k)) || "claude-opus-5";
  const [inR, outR] = rates[key];
  const inT = (usage?.input_tokens || 0) + (usage?.cache_read_input_tokens || 0);
  return +(((inT * inR) + ((usage?.output_tokens || 0) * outR)) / 1e6).toFixed(4);
}
