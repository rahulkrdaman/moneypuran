import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { slugify } from "@/lib/utils";
import { z } from "zod";

export async function GET() {
  const cats = await prisma.category.findMany({
    where:{isActive:true},orderBy:{sortOrder:"asc"},
    include:{_count:{select:{posts:{where:{status:"PUBLISHED"}}}}},
  });
  return NextResponse.json({success:true,data:cats});
}

const schema = z.object({
  name:z.string().min(2).max(100), description:z.string().optional(),
  image:z.string().url().optional().or(z.literal("")), color:z.string().optional(),
  parentId:z.string().uuid().optional(), sortOrder:z.number().int().default(0),
  metaTitle:z.string().max(70).optional(), metaDesc:z.string().max(160).optional(),
});

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  try {
    const data = schema.parse(await req.json());
    const slug = slugify(data.name);
    const cat = await prisma.category.create({data:{...data,slug,image:data.image||undefined,parentId:data.parentId||undefined}});
    return NextResponse.json({success:true,data:cat},{status:201});
  } catch(e) {
    if(e instanceof z.ZodError) return NextResponse.json({success:false,error:e.errors[0].message},{status:400});
    return NextResponse.json({success:false,error:"Failed to create category"},{status:500});
  }
}