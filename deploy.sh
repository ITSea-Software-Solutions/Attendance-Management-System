#!/usr/bin/env bash
# =============================================================================
# AMS — deploy / update script
# Usage: ./deploy.sh [branch]   (defaults to main)
#
# What this does:
#   1. Pulls latest code from git
#   2. Rebuilds the backend / queue / pdf-service images (code is baked into
#      these images at build time — a rebuild is required to pick up changes).
#      The frontend mounts its source as a volume and is served by the Vite dev
#      server, so frontend changes are picked up live — only a restart is needed.
#   3. Recreates containers
#   4. Waits for MySQL, runs migrations, rebuilds Laravel caches
#   5. Restarts the queue worker
#   6. Health check
#
# Run from the project root on the server (e.g. /var/www/attendance).
# =============================================================================
set -euo pipefail

BRANCH="${1:-main}"
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)
APP_URL_LOCAL="${APP_URL_LOCAL:-http://localhost}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}[$(date '+%H:%M:%S')] ✓ $1${NC}"; }
warn() { echo -e "${YELLOW}[$(date '+%H:%M:%S')] ⚠ $1${NC}"; }
fail() { echo -e "${RED}[$(date '+%H:%M:%S')] ✗ $1${NC}"; exit 1; }

cd "$PROJECT_DIR"
echo "=== AMS deploy started $(date) (branch: $BRANCH) ==="

# ── 1. Pull latest code ───────────────────────────────────────────────────────
log "Pulling latest code"
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

# ── 2. Rebuild images that bake in code ───────────────────────────────────────
log "Rebuilding backend / queue-worker / pdf-service images"
"${COMPOSE[@]}" build backend queue-worker pdf-service || fail "Image build failed"

# ── 3. Recreate containers ────────────────────────────────────────────────────
log "Recreating containers"
"${COMPOSE[@]}" up -d || fail "Compose up failed"

# ── 4. Wait for MySQL, migrate, cache ─────────────────────────────────────────
log "Waiting for MySQL to be healthy"
for i in $(seq 1 30); do
  if "${COMPOSE[@]}" exec -T mysql mysqladmin ping -h localhost --silent 2>/dev/null; then break; fi
  [ "$i" -eq 30 ] && fail "MySQL did not become ready"
  sleep 2
done
log "Running migrations"
"${COMPOSE[@]}" exec -T backend php artisan migrate --force || fail "Migration failed"
log "Rebuilding Laravel caches"
"${COMPOSE[@]}" exec -T backend php artisan optimize >/dev/null 2>&1 || warn "optimize failed (non-fatal)"
"${COMPOSE[@]}" exec -T backend php artisan storage:link --force >/dev/null 2>&1 || true

# ── 5. Restart queue worker (picks up new code via fresh image) ───────────────
log "Restarting queue worker"
"${COMPOSE[@]}" restart queue-worker >/dev/null 2>&1 || warn "queue-worker restart failed"

# ── 6. Health check ───────────────────────────────────────────────────────────
log "Health check"
sleep 4
CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$APP_URL_LOCAL/" || echo "000")
if [ "$CODE" = "200" ]; then
  log "Health check passed (HTTP $CODE)"
else
  fail "Health check returned HTTP $CODE — investigate ($APP_URL_LOCAL/)"
fi

echo ""
log "=== AMS deploy complete $(date) ==="
