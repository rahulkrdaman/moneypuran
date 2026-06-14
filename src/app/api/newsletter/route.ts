import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { z } from "zod";

const schema = z.object({ email: z.string().email(), firstName: z.string().optional() });

export async function POST(req: NextRequest) {
  try {
    const body = await req.json().catch(() => Object.fromEntries(new URL(req.url).searchParams));
    const { email, firstName } = schema.parse(body);
    await prisma.newsletter.upsert({
      where:  { email },
      create: { email, firstName, status: "ACTIVE" },
      update: { status: "ACTIVE" },
    });
    return NextResponse.json({ success: true, message: "Subscribed successfully" });
  } catch (e) {
    if (e instanceof z.ZodError) return NextResponse.json({ success: false, error: "Invalid email" }, { status: 400 });
    return NextResponse.json({ success: false, error: "Subscription failed" }, { status: 500 });
  }
}

export async function GET(req: NextRequest) {
  const user = await import("@/lib/auth").then(m => m.getAuthUser(req));
  if (!user || !["SUPER_ADMIN", "ADMIN"].includes(user.role)) {
    return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
  }
  const subscribers = await prisma.newsletter.findMany({ orderBy: { createdAt: "desc" }, take: 100 });
  return NextResponse.json({ success: true, data: subscribers });
}
