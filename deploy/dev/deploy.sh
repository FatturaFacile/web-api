#!/bin/sh
# Deploys the dev stack. Run on the VPS from ~/fatturafacile-dev by .github/workflows/deploy-dev.yml.
# API_TAG selects the image (default: dev). Every deploy rebuilds the dev DB from scratch (demo:reset, needs
# DEMO_RESET_ENABLED=true in .env), which also runs every 30 minutes from the scheduler: no real data stays on dev.
set -eu
cd "$(dirname "$0")"

[ -f .env ] || { echo ".env missing in $(pwd)"; exit 1; }
mkdir -p fe inglorious

docker compose pull app queue_worker scheduler
# Stop the scheduler and the queue worker first: the scheduler runs demo:reset every 30 minutes and a reset that
# overlaps this one drops tables under it (SQLSTATE 42P01). They are started again after the rebuild.
docker compose stop scheduler queue_worker
docker compose up -d postgres
docker compose up -d --remove-orphans app web

docker compose exec -T app sh -c '
  set -e
  php artisan config:cache
  # The image CMD already ran route:cache, and caching again over a cached collection raises a TypeError
  php artisan route:clear
  php artisan route:cache
  php -d memory_limit=512M artisan demo:reset
  php -d memory_limit=1G artisan l5-swagger:generate
'

docker compose up -d queue_worker scheduler

# Health check through the dev reverse proxy
i=0
until docker compose exec -T web wget -q -O /dev/null --header 'Host: dev-api.fatturafacile.eu' http://127.0.0.1/up; do
  i=$((i + 1))
  [ "$i" -ge 15 ] && { docker compose logs --tail=50 app; exit 1; }
  sleep 2
done
echo "dev deploy OK (${API_TAG:-dev})"
