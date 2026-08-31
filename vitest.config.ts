import { defineConfig } from "vitest/config";
import path from "node:path";

export default defineConfig({
  test: {
    environment: "node",
    include: ["src/**/*.test.ts"],
    env: { MARKET_DATA_MOCK: "true", NODE_ENV: "test" },
  },
  resolve: {
    alias: { "@": path.resolve(__dirname, "src") },
  },
});
