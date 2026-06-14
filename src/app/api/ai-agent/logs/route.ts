import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";

export async function GET(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN","EDITOR"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  const sp = new URL(req.url).searchParams;
  const page=parseInt(sp.get("page")||"1"), ps=20;
  const status = sp.get("status") || undefined;
  const where = status ? {status:status as any} : {};
  const [logs,total] = await Promise.all([
    prisma.aIContentLog.findMany({where,take:ps,skip:(page-1)*ps,orderBy:{createdAt:"desc"},
      include:{rssSource:{select:{id:true,name:true}},post:{select:{id:true,title:true,slug:true}}}}),
    prisma.aIContentLog.count({where}),
  ]);
  return NextResponse.json({success:true,data:logs,meta:{total,page,pageSize:ps,totalPages:Math.ceil(total/ps)}});
}