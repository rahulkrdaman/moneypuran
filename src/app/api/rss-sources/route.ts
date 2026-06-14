import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { z } from "zod";

export async function GET(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user) return NextResponse.json({ success: false, error: "Unauthorized" }, { status: 401 });
  const sources = await prisma.rssSource.findMany({ orderBy: { name: "asc" } });
  return NextResponse.json({ success: true, data: sources });
}

const schema = z.object({
  name:          z.string().min(2),
  url:           z.string().url(),
  category:      z.string().optional(),   // plain string label, not a FK
  isActive:      z.boolean().default(true),
  fetchInterval: z.number().int().min(15).max(1440).default(60),
});

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN", "ADMIN"].includes(user.role)) {
    return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
  }
  try {
    const data = schema.parse(await req.json());
    const source = await prisma.rssSource.create({ data });
    return NextResponse.json({ success: true, data: source }, { status: 201 });
  } catch (e) {
    if (e instanceof z.ZodError) return NextResponse.json({ success: false, error: e.errors[0].message }, { status: 400 });
    return NextResponse.json({ success: false, error: "Failed to create RSS source" }, { status: 500 });
  }
}
