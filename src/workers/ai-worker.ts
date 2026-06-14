import { CronJob } from "cron";
import { prisma } from "../lib/prisma";
import { processAllRssSources } from "../services/ai-agent";

console.log("🤖 MoneyPuran AI Worker starting...");

let isRunning = false;

async function runAIAgent() {
  if (isRunning) { console.log("AI Agent already running, skipping..."); return; }
  const settings = await prisma.aISettings.findFirst();
  if (!settings?.scheduleEnabled) { console.log("AI Agent schedule disabled"); return; }
  isRunning = true;
  console.log(`[${new Date().toISOString()}] Running AI Agent...`);
  try {
    await processAllRssSources();
    console.log(`[${new Date().toISOString()}] AI Agent completed`);
  } catch(e) {
    console.error("AI Agent error:", e);
  } finally {
    isRunning = false;
  }
}

// Run every 30 minutes by default
const job = new CronJob("*/30 * * * *", runAIAgent, null, true, "Asia/Kolkata");

// Run immediately on start
runAIAgent();

process.on("SIGTERM", () => { job.stop(); process.exit(0); });
process.on("SIGINT", () => { job.stop(); process.exit(0); });

console.log("✅ AI Worker scheduled (every 30 minutes)");
