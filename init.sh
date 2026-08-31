#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# AMS — First-time project initializer
#
# On Windows: use start.bat instead (double-click it).
# On Git Bash / WSL / Linux / Mac: bash init.sh
#
# Daily use after setup:
#   up.bat   / docker compose up -d
#   down.bat / docker compose down
# ─────────────────────────────────────────────────────────────────────────────

# Guard: must be run with bash, not sh or by double-clicking
if [ -z "$BASH_VERSION" ]; then
  echo "ERROR: Run this with bash, not sh."
  echo "       bash init.sh"
  echo "       Or on Windows: double-click start.bat"
  exit 1
fi

set -euo pipefail
trap 'echo ""; echo "[ERROR] Script failed at line $LINENO. Check the output above."; exit 1' ERR

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { echo -e "${GREEN}[AMS]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERR]${NC} $1"; exit 1; }

# ── Pre-flight checks ─────────────────────────────────────────────────────────
command -v docker >/dev/null 2>&1 || error "Docker not found. Install Docker Desktop first."
docker info >/dev/null 2>&1      || error "Docker is not running. Start Docker Desktop."

info "=== AMS — Attendance Management System ==="
info "Setting up with Docker Desktop..."

# ── 1. Create .env ────────────────────────────────────────────────────────────
if [ ! -f ".env" ]; then
  cp .env.example .env
  info "Created .env from .env.example"
  warn "Review .env and change passwords before production use."
else
  warn ".env already exists — skipping creation."
fi

# ── 2. Build and start Docker services ───────────────────────────────────────
# The backend Dockerfile uses multi-stage build:
#   Stage 1 (composer:2): creates fresh Laravel 11 project + installs packages
#   Stage 2 (php:8.3-fpm): copies our custom app files on top of the scaffold
# First build takes ~3-5 minutes (downloads Laravel + packages). Cached after that.

info "Building Docker images (first run takes 3-5 mins)..."
docker-compose build --no-cache

info "Starting all services..."
docker-compose up -d

# ── 3. Wait for MySQL to be healthy ──────────────────────────────────────────
info "Waiting for MySQL to be ready..."
attempt=0
max_attempts=30
until docker-compose exec -T mysql mysqladmin ping -h localhost --silent 2>/dev/null; do
  attempt=$((attempt + 1))
  [ $attempt -ge $max_attempts ] && error "MySQL did not become ready in time."
  echo -n "."
  sleep 2
done
echo ""
info "MySQL is ready."

# ── 4. Laravel setup ──────────────────────────────────────────────────────────
info "Generating application key..."
docker-compose exec -T backend php artisan key:generate --force

info "Running database migrations..."
docker-compose exec -T backend php artisan migrate --seed --force

info "Creating storage symlink..."
docker-compose exec -T backend php artisan storage:link --force 2>/dev/null || true

info "Optimizing..."
docker-compose exec -T backend php artisan optimize --force 2>/dev/null || true

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║          AMS Setup Complete                              ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║${NC}  Frontend  :  http://localhost:5173                      ${GREEN}║${NC}"
echo -e "${GREEN}║${NC}  API       :  http://localhost/api                       ${GREEN}║${NC}"
echo -e "${GREEN}║${NC}  PDF Svc   :  http://localhost:8001/health               ${GREEN}║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║${NC}  Credentials (change in production!)                     ${GREEN}║${NC}"
echo -e "${GREEN}║${NC}    superadmin@ams.local  /  Admin@12345                  ${GREEN}║${NC}"
echo -e "${GREEN}║${NC}    company@ams.local     /  Admin@12345                  ${GREEN}║${NC}"
echo -e "${GREEN}║${NC}    gate@ams.local        /  Admin@12345                  ${GREEN}║${NC}"
echo -e "${GREEN}║${NC}    vendor@ams.local      /  Admin@12345                  ${GREEN}║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
