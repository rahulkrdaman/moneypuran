#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# MoneyPuran – Production Setup Script
# Run: chmod +x scripts/setup.sh && ./scripts/setup.sh
# ─────────────────────────────────────────────────────────────────────────────
set -e

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
section() { echo -e "\n${GREEN}═══ $1 ═══${NC}"; }

# ── 1. Prerequisites ───────────────────────────────────────────────────────
section "Checking prerequisites"
command -v node  >/dev/null || error "Node.js not found. Install Node.js 20+"
command -v npm   >/dev/null || error "npm not found."
command -v psql  >/dev/null || warn  "psql not found — make sure PostgreSQL is reachable."
command -v redis-cli >/dev/null || warn "redis-cli not found — make sure Redis is reachable."
info "Node $(node -v) · npm $(npm -v)"

# ── 2. .env ────────────────────────────────────────────────────────────────
section "Environment"
if [ ! -f .env ]; then
  cp .env.example .env
  warn ".env created from .env.example — please fill in your secrets before continuing."
  echo; read -rp "Press ENTER when .env is configured, or CTRL-C to abort: "
else
  info ".env already exists."
fi

# ── 3. Dependencies ────────────────────────────────────────────────────────
section "Installing dependencies"
npm ci --prefer-offline || npm install

# ── 4. Prisma ─────────────────────────────────────────────────────────────
section "Database setup"
npx prisma generate
info "Running migrations…"
npx prisma migrate deploy || {
  warn "migrate deploy failed — trying db push (dev mode)."
  npx prisma db push
}
info "Seeding database…"
npx prisma db seed || warn "Seed skipped (already seeded or error)."

# ── 5. Build ──────────────────────────────────────────────────────────────
section "Building Next.js"
npm run build

# ── 6. Done ───────────────────────────────────────────────────────────────
section "Setup complete"
echo -e "${GREEN}MoneyPuran is ready to launch!${NC}"
echo ""
echo "  Start production:  npm start"
echo "  Start dev:         npm run dev"
echo "  Admin panel:       http://localhost:3000/admin"
echo "  Default login:     admin@moneypuran.com / Admin@123"
echo ""
echo -e "${YELLOW}Remember to change the default admin password!${NC}"
