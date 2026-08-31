/**
 * Provider resilience primitives — all in-process, dependency-free.
 *   - CircuitBreaker: stop hammering a provider that's failing.
 *   - TokenBucket:   respect provider rate limits.
 *   - coalesce:      collapse concurrent identical requests into one upstream call.
 *   - retry:         bounded exponential backoff.
 */

/* ─────────────────────────── Circuit breaker ─────────────────────────── */

export type CircuitState = "closed" | "open" | "half-open";

export class CircuitBreaker {
  private failures = 0;
  private state: CircuitState = "closed";
  private openedAt = 0;
  lastError: string | null = null;

  constructor(
    private readonly name: string,
    private readonly threshold = 5,
    private readonly cooldownMs = 30_000,
  ) {}

  get status(): CircuitState {
    if (this.state === "open" && Date.now() - this.openedAt >= this.cooldownMs) {
      this.state = "half-open";
    }
    return this.state;
  }

  canRequest(): boolean {
    return this.status !== "open";
  }

  onSuccess() {
    this.failures = 0;
    this.state = "closed";
    this.lastError = null;
  }

  onFailure(err: unknown) {
    this.lastError = err instanceof Error ? err.message : String(err);
    this.failures++;
    if (this.state === "half-open" || this.failures >= this.threshold) {
      this.state = "open";
      this.openedAt = Date.now();
    }
  }

  async run<T>(fn: () => Promise<T>): Promise<T> {
    if (!this.canRequest()) {
      throw new Error(`circuit open for ${this.name} (last: ${this.lastError ?? "n/a"})`);
    }
    try {
      const out = await fn();
      this.onSuccess();
      return out;
    } catch (e) {
      this.onFailure(e);
      throw e;
    }
  }
}

/* ─────────────────────────── Token bucket ─────────────────────────── */

export class TokenBucket {
  private tokens: number;
  private last: number;

  constructor(
    private readonly capacity: number,
    private readonly refillPerSec: number,
  ) {
    this.tokens = capacity;
    this.last = Date.now();
  }

  private refill() {
    const nowMs = Date.now();
    const add = ((nowMs - this.last) / 1000) * this.refillPerSec;
    if (add > 0) {
      this.tokens = Math.min(this.capacity, this.tokens + add);
      this.last = nowMs;
    }
  }

  get remaining(): number {
    this.refill();
    return Math.floor(this.tokens);
  }

  tryRemove(n = 1): boolean {
    this.refill();
    if (this.tokens >= n) {
      this.tokens -= n;
      return true;
    }
    return false;
  }

  /** wait until `n` tokens are available (bounded), then consume them. */
  async acquire(n = 1, maxWaitMs = 8000): Promise<boolean> {
    const deadline = Date.now() + maxWaitMs;
    while (!this.tryRemove(n)) {
      if (Date.now() >= deadline) return false;
      const needed = n - this.tokens;
      const waitMs = Math.min(500, Math.max(50, (needed / this.refillPerSec) * 1000));
      await sleep(waitMs);
    }
    return true;
  }
}

/* ─────────────────────────── Request coalescing ─────────────────────────── */

const inflight = new Map<string, Promise<unknown>>();

/**
 * If a call for `key` is already running, return the same promise instead of
 * making a second upstream request. Spec §27: "If 1,000 users request NVDA,
 * ONE upstream API request should serve them."
 */
export function coalesce<T>(key: string, fn: () => Promise<T>): Promise<T> {
  const existing = inflight.get(key) as Promise<T> | undefined;
  if (existing) return existing;
  const p = fn().finally(() => inflight.delete(key));
  inflight.set(key, p as Promise<unknown>);
  return p;
}

export function __clearInflight() {
  inflight.clear();
}

/* ─────────────────────────── Retry ─────────────────────────── */

export async function retry<T>(
  fn: () => Promise<T>,
  opts: { tries?: number; baseMs?: number; onRetry?: (attempt: number, err: unknown) => void } = {},
): Promise<T> {
  const tries = opts.tries ?? 3;
  const baseMs = opts.baseMs ?? 400;
  let lastErr: unknown;
  for (let attempt = 1; attempt <= tries; attempt++) {
    try {
      return await fn();
    } catch (e) {
      lastErr = e;
      if (attempt === tries) break;
      opts.onRetry?.(attempt, e);
      await sleep(baseMs * 2 ** (attempt - 1) + Math.random() * 100);
    }
  }
  throw lastErr;
}

export function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

/** fetch with a hard timeout (Node 20+/undici AbortController). */
export async function fetchJson<T = unknown>(
  url: string,
  init: RequestInit & { timeoutMs?: number } = {},
): Promise<{ status: number; data: T; headers: Headers }> {
  const { timeoutMs = 8000, ...rest } = init;
  const ac = new AbortController();
  const timer = setTimeout(() => ac.abort(), timeoutMs);
  try {
    const res = await fetch(url, {
      ...rest,
      signal: ac.signal,
      headers: { Accept: "application/json", "User-Agent": "MoneyPuran/1.0 (+https://moneypuran.com)", ...(rest.headers || {}) },
    });
    const text = await res.text();
    let data: T;
    try { data = text ? (JSON.parse(text) as T) : (null as T); }
    catch { data = text as unknown as T; }
    return { status: res.status, data, headers: res.headers };
  } finally {
    clearTimeout(timer);
  }
}
