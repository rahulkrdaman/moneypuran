import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { slugify } from "@/lib/utils";
import { z } from "zod";

export async function GET() {
  const tags = await prisma.tag.findMany({ orderBy:{name:"asc"}, include:{_count:{select:{posts:true}}} });
  return NextResponse.json({success:true,data:tags});
}

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN","EDITOR"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  const { name, color } = await req.json();
  if (!name) return NextResponse.json({success:false,error:"Name required"},{status:400});
  const slug = slugify(name);
  const tag = await prisma.tag.upsert({ where:{slug}, create:{name,slug,color}, update:{} });
  return NextResponse.json({success:true,data:tag},{status:201});
}