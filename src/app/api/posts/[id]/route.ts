import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { cacheDelPattern } from "@/lib/redis";

const include = { author:{select:{id:true,firstName:true,lastName:true,username:true,avatar:true}}, category:{select:{id:true,name:true,slug:true,color:true}}, tags:{include:{tag:true}}, _count:{select:{comments:true}} };

export async function GET(_: NextRequest, { params }: { params: Promise<{id:string}> }) {
  const { id } = await params;
  const post = await prisma.post.findUnique({ where:{id}, include });
  if (!post) return NextResponse.json({success:false,error:"Not found"},{status:404});
  return NextResponse.json({success:true,data:post});
}

export async function PUT(req: NextRequest, { params }: { params: Promise<{id:string}> }) {
  const user = await getAuthUser(req);
  if (!user) return NextResponse.json({success:false,error:"Unauthorized"},{status:401});
  const { id } = await params;
  const existing = await prisma.post.findUnique({ where:{id} });
  if (!existing) return NextResponse.json({success:false,error:"Not found"},{status:404});
  if (user.role === "AUTHOR" && existing.authorId !== user.userId) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  try {
    const body = await req.json();
    const { tagIds, ...data } = body;
    const wasPublished = existing.status !== "PUBLISHED" && data.status === "PUBLISHED";
    const post = await prisma.post.update({
      where:{id}, include,
      data: { ...data, publishedAt: wasPublished ? new Date() : existing.publishedAt,
        ...(tagIds !== undefined ? { tags:{ deleteMany:{}, create:tagIds.map((tid:string)=>({tagId:tid})) } } : {}) },
    });
    await cacheDelPattern("home:*");
    return NextResponse.json({success:true,data:post});
  } catch { return NextResponse.json({success:false,error:"Update failed"},{status:500}); }
}

export async function DELETE(req: NextRequest, { params }: { params: Promise<{id:string}> }) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN","EDITOR"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  const { id } = await params;
  await prisma.post.delete({ where:{id} });
  await cacheDelPattern("home:*");
  return NextResponse.json({success:true,message:"Post deleted"});
}