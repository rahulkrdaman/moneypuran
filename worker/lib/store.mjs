// Persistent state committed back to the repo by the GitHub Action.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const STATE_DIR = join(dirname(fileURLToPath(import.meta.url)), "..", "state");
const SEEN = join(STATE_DIR, "seen.json");
const DAILY = join(STATE_DIR, "daily.json");

function load(path, fallback) {
  try { return JSON.parse(readFileSync(path, "utf8")); } catch { return fallback; }
}
function save(path, data) {
  mkdirSync(STATE_DIR, { recursive: true });
  writeFileSync(path, JSON.stringify(data));
}

const seen = new Set(load(SEEN, []));
let daily = load(DAILY, { date: "", count: 0 });
const today = () => new Date().toISOString().slice(0, 10);

export const store = {
  has: (id) => seen.has(String(id)),
  add: (id) => seen.add(String(id)),
  dailyCount() {
    if (daily.date !== today()) daily = { date: today(), count: 0 };
    return daily.count;
  },
  bumpDaily() {
    if (daily.date !== today()) daily = { date: today(), count: 0 };
    daily.count += 1;
  },
  flush() {
    save(SEEN, [...seen].slice(-8000));
    save(DAILY, daily);
  },
};
