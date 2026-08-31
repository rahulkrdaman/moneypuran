/**
 * CLI entry for the market-data worker.
 *   npx tsx src/workers/market-worker.cli.ts            # long-running loop
 *   npx tsx src/workers/market-worker.cli.ts --once     # one pass (cron)
 *   npx tsx src/workers/market-worker.cli.ts --once --persist   # + write DB snapshot
 */
import { marketWorkerLoop } from "./market-worker";

marketWorkerLoop()
  .then(() => process.exit(0))
  .catch((e) => {
    console.error("[market-worker] fatal:", e);
    process.exit(1);
  });
