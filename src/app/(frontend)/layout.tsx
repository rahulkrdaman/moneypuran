import { prisma } from "@/lib/prisma";
import { Header } from "@/components/layout/Header";
import { Footer } from "@/components/layout/Footer";

async function getLayoutData() {
  const [categories, breakingPost] = await Promise.all([
    prisma.category.findMany({ where: { isActive: true }, orderBy: { sortOrder: "asc" } }),
    prisma.post.findFirst({ where: { isBreaking: true, status: "PUBLISHED" }, orderBy: { publishedAt: "desc" }, select: { title: true } }),
  ]);
  return { categories, breakingNews: breakingPost?.title };
}

export default async function FrontendLayout({ children }: { children: React.ReactNode }) {
  const { categories, breakingNews } = await getLayoutData();
  return (
    <>
      <Header categories={categories} breakingNews={breakingNews} />
      <main className="min-h-screen">{children}</main>
      <Footer categories={categories} />
    </>
  );
}
