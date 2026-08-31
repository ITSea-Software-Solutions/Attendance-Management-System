#!/usr/bin/env bash
# TrueCrew production bootstrap — Ubuntu 24.04, fresh server.
# READ EACH SECTION before running; run step by step, not blind.
set -euo pipefail

DOMAIN="${DOMAIN:-yourdomain.in}"
REPO="${REPO:-git@github.com:ITSea-Software-Solutions/truecrew.git}"
DIR=/var/www/truecrew

echo "── 1. Docker + basics ─────────────────────────────────────────────"
apt-get update -y && apt-get install -y ca-certificates curl git ufw
curl -fsSL https://get.docker.com | sh

echo "── 2. Firewall: only web + SSH ────────────────────────────────────"
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable
# NOTE: docker-published ports bypass ufw — the compose files only publish 80/443.

echo "── 3. Code + env ──────────────────────────────────────────────────"
git clone "$REPO" "$DIR" || (cd "$DIR" && git pull)
cd "$DIR"
[ -f backend/.env ] || cp deploy/production/.env.production.example backend/.env
echo ">>> EDIT backend/.env now (APP_KEY via: docker compose run --rm backend php artisan key:generate)"
echo ">>> BACK UP APP_KEY OFFLINE — it encrypts all biometric data."

echo "── 4. TLS certificate (standalone, before nginx runs) ─────────────"
apt-get install -y certbot
certbot certonly --standalone -d "$DOMAIN" --agree-tos -m admin@"$DOMAIN" -n || true
echo "0 3 * * 0 certbot renew --pre-hook 'docker stop ams_nginx' --post-hook 'docker start ams_nginx'" | crontab -

echo "── 5. Frontend production build (one-off node container) ──────────"
docker run --rm -v "$DIR/frontend":/app -w /app node:20-alpine sh -c "npm ci && npm run build"
mkdir -p /var/www/frontend-dist && cp -r "$DIR"/frontend/dist/* /var/www/frontend-dist/

echo "── 6. Production nginx conf + stack up ────────────────────────────"
sed "s/yourdomain.in/$DOMAIN/g" deploy/production/nginx-production.conf > nginx/conf.d/default.conf
# Production compose: base minus dev conveniences; nginx mounts certs + dist.
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
sleep 8
docker exec ams_backend php artisan migrate --force
docker exec ams_backend php artisan optimize

echo "── 7. Backups (nightly 02:30 IST) ─────────────────────────────────"
cp scripts/droplet-backup.sh /usr/local/bin/truecrew-backup.sh && chmod +x /usr/local/bin/truecrew-backup.sh
( crontab -l 2>/dev/null | grep -v truecrew-backup ; echo "0 21 * * * /usr/local/bin/truecrew-backup.sh >> /var/log/truecrew-backup.log 2>&1" ) | crontab -

echo "── 8. Verify everything ───────────────────────────────────────────"
docker exec ams_backend php artisan truecrew:test-comms
echo "Done. Create the super admin, then walk the GO_LIVE_PLAN.md runbook."
