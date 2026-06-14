import { NextRequest, NextResponse } from "next/server";
import { getAuthUser } from "@/lib/auth";
import { processAllRssSources } from "@/services/ai-agent";

export async function POST(req: NextRequest) {
  const user = await getAuthUser(req);
  if (!user || !["SUPER_ADMIN","ADMIN"].includes(user.role)) return NextResponse.json({success:false,error:"Forbidden"},{status:403});
  // Run in background
  processAllRssSources().catch(console.error);
  return NextResponse.json({success:true,message:"AI Agent started. Check logs for progress."});
}