import { NextRequest, NextResponse } from "next/server";
import { getAuthUser } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

export async function GET(req: NextRequest) {
  const payload = await getAuthUser(req);
  if (!payload) {
    return NextResponse.json({ success: false, error: "Unauthorized" }, { status: 401 });
  }
  const user = await prisma.user.findUnique({
    where: { id: payload.userId },   // now correctly typed: JwtPayload.userId
    select: {
      id: true, email: true, firstName: true, lastName: true,
      username: true, role: true, avatar: true, bio: true, isActive: true,
    },
  });
  if (!user) {
    return NextResponse.json({ success: false, error: "User not found" }, { status: 404 });
  }
  return NextResponse.json({ success: true, data: user });
}
