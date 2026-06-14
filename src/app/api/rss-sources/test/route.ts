import { NextRequest, NextResponse } from "next/server";
import { getAuthUser } from "@/lib/auth";

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user) return NextResponse.json({ success: false, error: "Unauthorized" }, { status: 401 });

  const { url } = await req.json();
  if (!url) return NextResponse.json({ success: false, error: "URL required" }, { status: 400 });

  try {
    const Parser = (await import("rss-parser")).default;
    const parser = new Parser({ timeout: 10000 });
    const feed = await parser.parseURL(url);
    return NextResponse.json({ success: true, count: feed.items?.length || 0, title: feed.title });
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : "Invalid RSS feed";
    return NextResponse.json({ success: false, error: message });
  }
}
