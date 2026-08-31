import { NextRequest, NextResponse } from "next/server";
import { getAuthUser } from "@/lib/auth";
import { writeFile, mkdir } from "fs/promises";
import path from "path";

const ALLOWED_TYPES = ["image/jpeg", "image/jpg", "image/png", "image/webp", "image/gif"];
const MAX_SIZE = 10 * 1024 * 1024; // 10MB

export async function POST(req: NextRequest) {
  try {
    const user = await getAuthUser(req);
    if (!user || !["SUPER_ADMIN", "ADMIN", "EDITOR"].includes(user.role)) {
      return NextResponse.json({ success: false, error: "Forbidden" }, { status: 403 });
    }

    const formData = await req.formData();
    const file = formData.get("file") as File | null;
    if (!file) return NextResponse.json({ success: false, error: "No file provided" }, { status: 400 });

    // Validate type
    if (!ALLOWED_TYPES.includes(file.type)) {
      return NextResponse.json({ success: false, error: "Invalid file type. Only JPG, PNG, WebP, GIF allowed." }, { status: 400 });
    }
    // Validate size
    if (file.size > MAX_SIZE) {
      return NextResponse.json({ success: false, error: "File too large. Max 10MB." }, { status: 400 });
    }

    const bytes = await file.arrayBuffer();
    const buffer = Buffer.from(bytes);

    // Optimise with sharp if available
    let finalBuffer: Buffer = buffer;
    let ext = path.extname(file.name).toLowerCase() || ".jpg";
    try {
      const sharp = (await import("sharp")).default;
      finalBuffer = Buffer.from(await sharp(buffer)
        .resize(1200, 800, { fit: "inside", withoutEnlargement: true })
        .webp({ quality: 85 })
        .toBuffer());
      ext = ".webp";
    } catch {
      // sharp not installed or error — use original
    }

    // Save to public/uploads
    const slug = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const filename = `${slug}${ext}`;
    const uploadDir = path.join(process.cwd(), "public", "uploads");
    await mkdir(uploadDir, { recursive: true });
    await writeFile(path.join(uploadDir, filename), finalBuffer);

    const url = `/uploads/${filename}`;
    return NextResponse.json({ success: true, data: { url, filename, size: finalBuffer.byteLength } }, { status: 201 });
  } catch (err) {
    console.error("Upload error:", err);
    return NextResponse.json({ success: false, error: "Upload failed" }, { status: 500 });
  }
}
