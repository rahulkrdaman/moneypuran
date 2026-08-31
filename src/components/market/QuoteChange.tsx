import { cn } from "@/lib/utils";

/** The one place green/red is applied (spec §4/§31). Nowhere else. */
export function QuoteChange({
  changeAbs, changePct, className, showAbs = true,
}: {
  changeAbs?: number | null;
  changePct?: number | null;
  className?: string;
  showAbs?: boolean;
}) {
  const dir = changePct == null || !Number.isFinite(changePct) || changePct === 0
    ? "flat" : changePct > 0 ? "up" : "down";
  const color = dir === "up" ? "text-finance-green" : dir === "down" ? "text-finance-red" : "text-muted-foreground";
  const sign = dir === "up" ? "+" : "";
  const arrow = dir === "up" ? "▲" : dir === "down" ? "▼" : "•";
  return (
    <span className={cn("inline-flex items-baseline gap-1 tabular-nums font-medium", color, className)}>
      <span aria-hidden>{arrow}</span>
      {showAbs && changeAbs != null && Number.isFinite(changeAbs) && (
        <span>{sign}{Math.abs(changeAbs).toLocaleString("en-US", { maximumFractionDigits: 4 })}</span>
      )}
      <span>
        {changePct == null || !Number.isFinite(changePct) ? "—" : `${sign}${changePct.toFixed(2)}%`}
      </span>
    </span>
  );
}

export function FreshnessBadge({ label }: { label: string }) {
  const isMock = /demo/i.test(label);
  return (
    <span
      className={cn(
        "inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide",
        isMock ? "bg-brand-500/15 text-brand-600 dark:text-brand-400" : "bg-muted text-muted-foreground",
      )}
      title={isMock ? "This is demonstration data, not a live market price." : undefined}
    >
      {label}
    </span>
  );
}
