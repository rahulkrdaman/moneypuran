import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getAuthUser } from "@/lib/auth";
import { z } from "zod";

const createSchema = z.object({
  postId:      z.string(),
  content:     z.string().min(2).max(2000),
  authorName:  z.string().min(2).max(100).optional(),
  authorEmail: z.string().email().optional(),
  parentId:    z.string().optional(),
});

export async function GET(req: NextRequest) {
  const { searchParams } = new URL(req.url);
  const postId = searchParams.get("postId");
  const page   = parseInt(searchParams.get("page") || "1");
  const limit  = Math.min(parseInt(searchParams.get("limit") || "20"), 50);
  const status = searchParams.get("status") || "APPROVED";

  const where = {
    ...(postId && { postId }),
    status,
    parentId: null,
  };

  const [comments, total] = await Promise.all([
    prisma.comment.findMany({
      where, skip: (page - 1) * limit, take: limit,
      orderBy: { createdAt: "desc" },
      include: {
        replies: { where: { status: "APPROVED" }, orderBy: { createdAt: "asc" }, take: 10 },
      },
    }),
    prisma.comment.count({ where }),
  ]);

  return NextResponse.json({
    success: true,
    data: comments,
    pagination: { page, limit, total, totalPages: Math.ceil(total / limit) },
  });
}

export async function POST(req: NextRequest) {
  try {
    const body   = await req.json();
    const parsed = createSchema.safeParse(body);
    if (!parsed.success) return NextResponse.json({ success:false, error:parsed.error.issues[0].message }, { status:400 });

    const { postId, content, authorName, authorEmail, parentId } = parsed.data;

    const post = await prisma.post.findUnique({ where: { id: postId }, select: { id:true, status:true } });
    if (!post || post.status !== "PUBLISHED") {
      return NextResponse.json({ success:false, error:"Post not found" }, { status:404 });
    }

    const authUser = await getAuthUser(req);
    const status   = authUser ? "APPROVED" : "PENDING";

    const comment = await prisma.comment.create({
      data: {
        postId,
        content,
        parentId:    parentId || null,
        status,
        authorId:    authUser?.userId || null,
        authorName:  authUser ? `${authUser.email}` : (authorName || "Anonymous"),
        authorEmail: authUser?.email || authorEmail || null,
      },
    });

    return NextResponse.json({ success:true, data:comment, pending: status === "PENDING" }, { status:201 });
  } catch (err) {
    console.error(err);
    return NextResponse.json({ success:false, error:"Failed to post comment" }, { status:500 });
  }
}
