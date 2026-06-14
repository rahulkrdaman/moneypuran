import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { slugify } from "@/lib/utils";

export async function PUT(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN", "ADMIN", "EDITOR"].includes(user.role)) {
    return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
  }
  const { name, color } = await req.json();
  const data: Record<string, string> = {};
  if (name) { data.name = name; data.slug = slugify(name); }
  if (color) data.color = color;
  const tag = await prisma.tag.update({ where: { id }, data });
  return NextResponse.json({ success: true, data: tag });
}

export async function DELETE(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN", "ADMIN"].includes(user.role)) {
    return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
  }
  await prisma.tag.delete({ where: { id } });
  return NextResponse.json({ success: true });
}
