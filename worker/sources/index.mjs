import { sec } from "./sec.mjs";
import { fed } from "./fed.mjs";
import { bls } from "./bls.mjs";
import { rbi } from "./rbi.mjs";
import { marketwrap } from "./marketwrap.mjs";
import { makeGenericSource } from "./generic.mjs";

// RBI regulatory notifications — the feed <description> usually carries the full
// circular text, so no page fetch needed.
const rbiNotifications = makeGenericSource({
  id: "rbi_notif",
  name: "Reserve Bank of India (RBI) — notifications",
  feeds: ["https://www.rbi.org.in/notifications_rss.xml"],
  skip: "(auction|Money Market|Treasury Bill|91 Days|182 Days|364 Days|Directions - Compounding|Premature redemption)",
  minLen: 500,
});

// Securities and Exchange Board of India. NOTE: SEBI's only public RSS
// (sebirss.xml) is an enforcement/recovery-order feed, not a press-release feed,
// so this is disabled by default in config.json. Kept here for when a better
// SEBI feed becomes available or you point `feeds` at a scrape.
const sebi = makeGenericSource({
  id: "sebi",
  name: "Securities and Exchange Board of India (SEBI)",
  feeds: ["https://www.sebi.gov.in/sebirss.xml"],
  skip: "(Recovery Certificate|Attachment|Release Order|Remittance|Adjudication Order|Defaulter|Recovery Proceedings|RC No|Notice of Demand)",
  minLen: 500,
  fetchPage: true,
  contentHints: [/<div[^>]*id=["'][^"']*(?:content|majorHeading)[^"']*["'][^>]*>/i, /<main[^>]*>/i],
});

export const SOURCES = {
  sec,
  fed,
  bls,
  rbi,
  rbi_notif: rbiNotifications,
  sebi,
  marketwrap,
};
