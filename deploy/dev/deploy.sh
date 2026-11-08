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
# Start them again on any exit, also when the deploy fails half way (up -d is idempotent)
trap 'docker compose up -d queue_worker scheduler >/dev/null 2>&1 || true' EXIT
docker compose up -d postgres
docker compose up -d --remove-orphans app web

# The image CMD caches config, routes and views and then starts php-fpm. Running artisan cache commands while it is
# still caching races with it: a route:clear in the middle breaks its `&&` chain, the container restarts and this exec
# is killed (exit 137). Wait for the php-fpm master instead, so the caches are built once, by the CMD.
i=0
until docker compose exec -T app pgrep -f 'php-fpm: master' >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -ge 60 ] && { docker compose logs --tail=50 app; exit 1; }
  sleep 2
done

# nginx resolves ffdev_app only at start: a recreated app gets a new IP, so restart web to follow it
docker compose restart web

docker compose exec -T app sh -c '
  set -e
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
