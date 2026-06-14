import Bull from "bull";

const redisUrl = process.env.REDIS_URL || "redis://localhost:6379";

const bullOpts = {
  redis: redisUrl,
  // If Redis is not available, Bull will retry connections but won't crash the app
  createClient: (type: "client" | "subscriber" | "bclient") => {
    const Redis = require("ioredis");
    return new Redis(redisUrl, {
      maxRetriesPerRequest: type === "client" ? 0 : null,
      enableReadyCheck: false,
      retryStrategy: (times: number) => (times > 3 ? null : Math.min(times * 500, 3000)),
      lazyConnect: true,
    });
  },
};

// AI content processing queue
export const aiQueue = new Bull("ai-content", bullOpts as any);

// Email queue
export const emailQueue = new Bull("email", bullOpts as any);

// RSS fetch queue
export const rssQueue = new Bull("rss-fetch", bullOpts as any);

// Suppress unhandled errors from disconnected queues
[aiQueue, emailQueue, rssQueue].forEach(q => q.on("error", () => {}));

export type AIJobData = {
  rssItemId: string;
  title: string;
  content: string;
  url: string;
  sourceName: string;
  sourceId: string;
  categoryId?: string;
};

export type EmailJobData = {
  to: string;
  subject: string;
  html: string;
  text?: string;
};
