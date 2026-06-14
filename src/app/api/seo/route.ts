import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";

export async function GET() {
  const settings = await prisma.seoSettings.findFirst();
  return NextResponse.json({ success: true, data: settings });
}

export async function PUT(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN", "ADMIN"].includes(user.role)) {
    return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
  }
  const body = await req.json();
  const existing = await prisma.seoSettings.findFirst();
  const settings = existing
    ? await prisma.seoSettings.update({ where: { id: existing.id }, data: body })
    : await prisma.seoSettings.create({ data: body });
  return NextResponse.json({ success: true, data: settings });
}
