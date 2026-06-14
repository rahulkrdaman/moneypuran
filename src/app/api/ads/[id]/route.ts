import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export async function POST(req: NextRequest, { params }: { params: Promise<{id:string}> }) {
  const { id } = await params;
  const url = req.url;
  if (url.endsWith("/impression")) {
    await prisma.advertisement.update({ where:{id}, data:{impressions:{increment:1}} }).catch(()=>{});
  } else if (url.endsWith("/click")) {
    await prisma.advertisement.update({ where:{id}, data:{clicks:{increment:1}} }).catch(()=>{});
  }
  return NextResponse.json({success:true});
}

export async function PUT(req: NextRequest, { params }: { params: Promise<{id:string}> }) {
  const { id } = await params;
  const data = await req.json();
  const ad = await prisma.advertisement.update({ where:{id}, data });
  return NextResponse.json({success:true,data:ad});
}

export async function DELETE(req: NextRequest, { params }: { params: Promise<{id:string}> }) {
  const { id } = await params;
  await prisma.advertisement.delete({ where:{id} });
  return NextResponse.json({success:true});
}