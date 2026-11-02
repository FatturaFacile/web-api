#!/bin/sh
# Deploys the dev stack. Run on the VPS from ~/fatturafacile-dev by .github/workflows/deploy-dev.yml.
# API_TAG selects the image (default: dev). The dev DB is kept across deploys: only migrations run.
set -eu
cd "$(dirname "$0")"

[ -f .env ] || { echo ".env missing in $(pwd)"; exit 1; }
mkdir -p fe

docker compose pull app queue_worker scheduler
docker compose up -d postgres
docker compose up -d --remove-orphans app web

docker compose exec -T app sh -c '
  set -e
  php -d memory_limit=512M artisan migrate --force
  php artisan config:cache
  php artisan route:cache
  php -d memory_limit=512M artisan l5-swagger:generate
'

# Demo data only once, on the first deploy of an empty DB
if [ ! -f .seeded ]; then
  docker compose exec -T app php -d memory_limit=512M artisan db:seed --force
  touch .seeded
fi

docker compose up -d queue_worker scheduler

# Health check through the dev reverse proxy
i=0
until docker compose exec -T web wget -q -O /dev/null --header 'Host: dev-api.fatturafacile.eu' http://127.0.0.1/up; do
  i=$((i + 1))
  [ "$i" -ge 15 ] && { docker compose logs --tail=50 app; exit 1; }
  sleep 2
done
echo "dev deploy OK (${API_TAG:-dev})"
