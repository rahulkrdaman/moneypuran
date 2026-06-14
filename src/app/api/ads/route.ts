import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";

export async function GET(req: NextRequest) {
  const sp = new URL(req.url).searchParams;
  const placement = sp.get("placement") || undefined;
  const ads = await prisma.advertisement.findMany({
    where:{ isActive:true, ...(placement?{placement:placement as any}:{}) },
    orderBy:{priority:"desc"},
  });
  return NextResponse.json({success:true,data:ads});
}

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  const data = await req.json();
  const ad = await prisma.advertisement.create({ data });
  return NextResponse.json({success:true,data:ad},{status:201});
}