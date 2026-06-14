"use client";
import Link from "next/link";
import { ChevronLeft, ChevronRight, MoreHorizontal } from "lucide-react";

interface PaginationProps {
  page: number;
  totalPages: number;
  baseUrl: string; // e.g. "/markets" or "/search?q=foo"
}

function pageUrl(baseUrl: string, page: number) {
  const hasQ = baseUrl.includes("?");
  return page === 1 ? baseUrl : `${baseUrl}${hasQ ? "&" : "?"}page=${page}`;
}

export default function Pagination({ page, totalPages, baseUrl }: PaginationProps) {
  if (totalPages <= 1) return null;

  function getPages(): (number | "...")[] {
    if (totalPages <= 7) return Array.from({ length: totalPages }, (_, i) => i + 1);
    const pages: (number | "...")[] = [1];
    if (page > 3) pages.push("...");
    for (let p = Math.max(2, page - 1); p <= Math.min(totalPages - 1, page + 1); p++) pages.push(p);
    if (page < totalPages - 2) pages.push("...");
    pages.push(totalPages);
    return pages;
  }

  const pages = getPages();

  const btnClass = (active = false, disabled = false) =>
    `inline-flex items-center justify-center h-9 w-9 rounded-lg text-sm font-medium transition-all border
    ${active ? "bg-brand-600 text-white border-brand-600 shadow" : ""}
    ${!active && !disabled ? "border-border hover:bg-muted hover:border-border text-foreground" : ""}
    ${disabled ? "border-transparent text-muted-foreground cursor-not-allowed opacity-40" : ""}`;

  return (
    <nav className="flex items-center justify-center gap-1 py-8" aria-label="Pagination">
      {/* Prev */}
      {page > 1 ? (
        <Link href={pageUrl(baseUrl, page - 1)} className={btnClass(false, false)} aria-label="Previous page">
          <ChevronLeft className="h-4 w-4" />
        </Link>
      ) : (
        <span className={btnClass(false, true)}><ChevronLeft className="h-4 w-4" /></span>
      )}

      {/* Pages */}
      {pages.map((p, i) =>
        p === "..." ? (
          <span key={`ellipsis-${i}`} className="inline-flex items-center justify-center h-9 w-9 text-muted-foreground">
            <MoreHorizontal className="h-4 w-4" />
          </span>
        ) : (
          <Link key={p} href={pageUrl(baseUrl, p as number)}
            className={btnClass(p === page)} aria-current={p === page ? "page" : undefined}>
            {p}
          </Link>
        )
      )}

      {/* Next */}
      {page < totalPages ? (
        <Link href={pageUrl(baseUrl, page + 1)} className={btnClass(false, false)} aria-label="Next page">
          <ChevronRight className="h-4 w-4" />
        </Link>
      ) : (
        <span className={btnClass(false, true)}><ChevronRight className="h-4 w-4" /></span>
      )}
    </nav>
  );
}
