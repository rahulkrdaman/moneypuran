// Evergreen explainer writer for MoneyPuran — a sibling of llm.mjs tuned for
// "what is / how does / how to" investing and personal-finance articles that
// answer questions US and Indian readers search every day. It is ALLOWED to use
// well-established general knowledge (it is not re-reporting a single source),
// but must stay factually careful and always carry a not-financial-advice
// disclaimer.
//
// Dependency-free. Supports Gemini (free tier) and Anthropic.

function systemPrompt(publication, category, keyword) {
  return [
    `You are a senior explainer-desk editor for "${publication}", an English business & personal-finance site.`,
    `You write ORIGINAL, in-depth, EVERGREEN explainer articles — the clearest answer on the internet`,
    `to an investing / money question, useful to readers in BOTH the United States and India.`,
    ``,
    `TARGET QUESTION CATEGORY: ${category}`,
    `FOCUS KEYWORD (use exactly, do not change): ${keyword}`,
    ``,
    `ACCURACY & SAFETY — non-negotiable:`,
    `- Be correct and current as of 2026. Use stable, well-established facts: how an instrument or`,
    `  process works, the standard definition, the usual mechanics, typical ranges.`,
    `- NEVER invent specific current prices, exact fees, tax rates, contribution limits, phone numbers`,
    `  or URLs you are not certain of. Where a number changes yearly (e.g. 401(k) limits, ISA/PPF`,
    `  limits, tax slabs) say it is set annually and tell the reader to check the official source`,
    `  (IRS.gov, SEC.gov / investor.gov, incometax.gov.in, sebi.gov.in, rbi.org.in).`,
    `- Where US and Indian rules differ (accounts, taxes, regulators), briefly cover BOTH.`,
    `- This is general education, NOT personalised advice. No hype, no "get rich", no guaranteed`,
    `  returns, no specific stock/coin tips, no market timing calls.`,
    ``,
    `SEO REQUIREMENTS — follow ALL:`,
    `- focus_keyword: exactly "${keyword}".`,
    `- seo_title: <= 60 characters. MUST BEGIN with the focus keyword. Include the year 2026 or a number.`,
    `- headline (on-page H1): <= 75 chars, natural question / how-to phrasing, contains the focus keyword.`,
    `- meta_description: 120-155 chars, contains the focus keyword once, ends with a concrete reason to read.`,
    `- slug: lowercase ASCII, the focus keyword words plus at most one more, hyphen-separated.`,
    `- body_html: clean HTML using <p>, <h2>, <h3>, <ul><li>, <ol><li>, <strong>. 800-1100 words.`,
    `    * the FIRST sentence must contain the focus keyword`,
    `    * at least TWO <h2> headings must contain the focus keyword`,
    `    * use the focus keyword 6-10 times total, naturally`,
    `    * if the question is "how to", include a numbered <ol> under an <h2> like "Step by step"`,
    `    * include a <ul> of the key facts / criteria / things you need where relevant`,
    `    * a short "US vs India" comparison where the topic differs by country`,
    `    * 5-7 <h2> sections, short paragraphs (2-4 sentences)`,
    `    * near the end an <h2> "Frequently asked questions" with 3-4 pairs as`,
    `      <p><strong>Question?</strong> Answer.</p>`,
    `    * the FINAL <p> must be exactly: <p><em>This article is for general education and is not`,
    `      investment, tax or financial advice. Rules and figures change — check the official source`,
    `      or a licensed adviser before acting.</em></p>`,
    `- key_points: 3-5 English bullets — the most useful takeaways.`,
    `- category: exactly "${category}".`,
    `- tags: 3-5 short English tags.`,
    `- image_query: 2-4 ENGLISH words for a stock photo that illustrates the topic — an object, place`,
    `  or activity, NEVER a person. Concrete and photographable. Examples: "stock market chart screen",`,
    `  "piggy bank and coins", "New York Stock Exchange building", "gold bars", "Bitcoin physical coin".`,
    `- official_url: the single most relevant official regulator / government URL (https://...), or "".`,
    `  Only well-known official domains (sec.gov, investor.gov, irs.gov, federalreserve.gov,`,
    `  sebi.gov.in, rbi.org.in, incometax.gov.in, nseindia.com, bseindia.com).`,
    `- confidence: 0-1.`,
  ].join("\n");
}

const JSON_SHAPE = `Return ONLY a JSON object (no markdown fence):
{"focus_keyword":str,"headline":str,"seo_title":str,"slug":str,"meta_description":str,"dek":str,
"key_points":[str],"body_html":str,"category":str,"tags":[str],"image_query":str,"official_url":str,"confidence":number}`;

function userPrompt(question, brief, keyword, category, withShape) {
  return [
    `QUESTION TO ANSWER: ${question}`,
    `FOCUS KEYWORD: ${keyword}`,
    `CATEGORY: ${category}`,
    ``,
    `RESEARCH BRIEF (accurate anchor facts — expand on these, do not contradict them, do not add invented specifics):`,
    brief,
    ``,
    `Write the full evergreen English article now.`,
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

async function geminiCall({ apiKey, model, question, brief, keyword, category, publication }) {
  const url = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent`;
  const body = {
    system_instruction: { parts: [{ text: systemPrompt(publication, category, keyword) }] },
    contents: [{ role: "user", parts: [{ text: userPrompt(question, brief, keyword, category, true) }] }],
    generationConfig: { temperature: 0.5, maxOutputTokens: 9000, responseMimeType: "application/json" },
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

async function geminiWrite(opts) {
  const models = Array.isArray(opts.model) ? opts.model : [opts.model];
  let lastErr;
  for (const model of models) {
    try {
      return await geminiCall({ ...opts, model });
    } catch (e) {
      lastErr = e;
      const retryable = e.status === 429 || e.status === 503 || /quota|high demand|overloaded/i.test(e.message);
      if (!retryable) throw e;
    }
  }
  throw lastErr;
}

/* -------------------------------- Anthropic ------------------------------- */

async function anthropicWrite({ apiKey, model, effort, question, brief, keyword, category, publication }) {
  const res = await fetch("https://api.anthropic.com/v1/messages", {
    method: "POST",
    headers: { "x-api-key": apiKey, "anthropic-version": "2023-06-01", "content-type": "application/json" },
    body: JSON.stringify({
      model, max_tokens: 9000,
      system: systemPrompt(publication, category, keyword),
      thinking: { type: "adaptive" },
      output_config: { effort: effort || "medium" },
      messages: [{ role: "user", content: userPrompt(question, brief, keyword, category, true) }],
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

export async function writeEvergreen(opts) {
  if (opts.provider === "anthropic") return anthropicWrite(opts);
  return geminiWrite(opts);
}
