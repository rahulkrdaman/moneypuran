import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";

export async function GET(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  const users = await prisma.user.findMany({
    orderBy:{createdAt:"desc"},
    select:{id:true,email:true,firstName:true,lastName:true,username:true,role:true,isActive:true,createdAt:true,_count:{select:{posts:true}}},
  });
  return NextResponse.json({success:true,data:users});
}