"use client";
import { useEffect, useRef } from "react";
import { Advertisement } from "@/types";

interface AdUnitProps { ad: Advertisement; }

export function AdUnit({ ad }: AdUnitProps) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    // Track impression
    fetch(`/api/ads/${ad.id}/impression`, { method: "POST" }).catch(() => {});

    // Initialize AdSense
    if (ad.type === "ADSENSE") {
      try { (window.adsbygoogle = window.adsbygoogle || []).push({}); } catch { /* */ }
    }
  }, [ad.id, ad.type]);

  function handleClick() {
    fetch(`/api/ads/${ad.id}/click`, { method: "POST" }).catch(() => {});
  }

  return (
    <div className="ad-container" ref={ref}>
      <div>
        <p className="ad-label">Advertisement</p>
        {ad.type === "IMAGE" && ad.imageUrl && ad.linkUrl && (
          <a href={ad.linkUrl} target="_blank" rel="noopener noreferrer nofollow" onClick={handleClick}>
            <img src={ad.imageUrl} alt={ad.altText || "Advertisement"} width={ad.width} height={ad.height} loading="lazy" className="max-w-full" />
          </a>
        )}
        {ad.type === "ADSENSE" && (
          <ins className="adsbygoogle" style={{ display:"block", width: ad.width, height: ad.height }}
            data-ad-client={process.env.NEXT_PUBLIC_ADSENSE_ID}
            data-ad-slot={ad.adsenseSlot} data-ad-format="auto" data-full-width-responsive="true" />
        )}
        {(ad.type === "CUSTOM_HTML" || ad.type === "AFFILIATE") && ad.htmlCode && (
          <div dangerouslySetInnerHTML={{ __html: ad.htmlCode }} onClick={handleClick} />
        )}
      </div>
    </div>
  );
}

declare global { interface Window { adsbygoogle: unknown[] } }
