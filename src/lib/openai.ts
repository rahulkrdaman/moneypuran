import OpenAI from "openai";

let openaiClient: OpenAI | null = null;

export function getOpenAI(): OpenAI {
  if (!openaiClient) {
    openaiClient = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });
  }
  return openaiClient;
}

export interface RewriteResult {
  title: string;
  slug: string;
  excerpt: string;
  content: string;
  metaDescription: string;
  tags: string[];
  qualityScore: number;
  tokensUsed: number;
}

export async function rewriteArticle(
  originalTitle: string,
  originalContent: string,
  categoryName: string = "Finance"
): Promise<RewriteResult> {
  const openai = getOpenAI();
  const prompt = `You are a professional financial journalist. Rewrite the following article to be unique, SEO-friendly, and engaging. 

ORIGINAL TITLE: ${originalTitle}
CATEGORY: ${categoryName}
ORIGINAL CONTENT: ${originalContent.substring(0, 3000)}

Return a JSON object with these exact fields:
- title: (compelling SEO title, 50-60 chars)
- slug: (URL-friendly slug from title)
- excerpt: (engaging summary, 150-160 chars)
- content: (rewritten full HTML article, 600-1000 words, use h2/h3 headings, p tags)
- metaDescription: (SEO meta description, 150-160 chars)
- tags: (array of 5-8 relevant tags)
- qualityScore: (float 0-1, your confidence in quality)

Write in a professional financial journalism style. Include market context, expert perspective, and actionable insights.`;

  const response = await openai.chat.completions.create({
    model: process.env.OPENAI_MODEL || "gpt-4o-mini",
    messages: [
      { role: "system", content: "You are a professional financial journalist specializing in Indian and global markets. Always respond with valid JSON only." },
      { role: "user", content: prompt },
    ],
    response_format: { type: "json_object" },
    temperature: 0.7,
    max_tokens: 2000,
  });

  const result = JSON.parse(response.choices[0].message.content || "{}");
  return { ...result, tokensUsed: response.usage?.total_tokens || 0 };
}

export async function detectDuplicate(content1: string, content2: string): Promise<number> {
  const openai = getOpenAI();
  const response = await openai.chat.completions.create({
    model: "gpt-4o-mini",
    messages: [{
      role: "user",
      content: `Compare these two texts and return a JSON with "similarity" (0-1 float) indicating how similar they are:
TEXT1: ${content1.substring(0, 500)}
TEXT2: ${content2.substring(0, 500)}
Return JSON: {"similarity": 0.0}`,
    }],
    response_format: { type: "json_object" },
    max_tokens: 50,
  });
  const result = JSON.parse(response.choices[0].message.content || '{"similarity":0}');
  return result.similarity || 0;
}

export async function generateImage(prompt: string): Promise<string | null> {
  try {
    const openai = getOpenAI();
    const response = await openai.images.generate({
      model: "dall-e-3",
      prompt: `Professional financial news image: ${prompt}. Clean, corporate style, no text.`,
      size: "1792x1024",
      quality: "standard",
      n: 1,
    });
    return response.data[0]?.url || null;
  } catch { return null; }
}
