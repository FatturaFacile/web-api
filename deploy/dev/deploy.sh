#!/bin/sh
# Deploys the dev stack. Run on the VPS from ~/fatturafacile-dev by .github/workflows/deploy-dev.yml.
# API_TAG selects the image (default: dev). Every deploy rebuilds the dev DB from scratch (demo:reset, needs
# DEMO_RESET_ENABLED=true in .env), which also runs every 30 minutes from the scheduler: no real data stays on dev.
set -eu
cd "$(dirname "$0")"

# 1. Controllo del .env prima del cambio di versione
[ -f .env ] || { echo "ERROR: .env missing in $(pwd)" >&2; exit 1; }

# Validazione parametri HTTPS e sicurezza nel .env
if ! grep -qE '^APP_URL=https://' .env; then
  echo "ERROR: APP_URL must be an https URL in .env to prevent boot refusal." >&2
  exit 1
fi

if grep -qE '^SESSION_SECURE_COOKIE=false' .env; then
  echo "ERROR: SESSION_SECURE_COOKIE cannot be false: HTTPS enforcement requires secure cookies." >&2
  exit 1
fi

mkdir -p fe inglorious

# Salva immagine precedente per eventuale rollback automatico
PREV_IMAGE=$(docker compose images app --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | head -n 1 || true)

echo "Pulling new images (API_TAG=${API_TAG:-dev})..."
docker compose pull app queue_worker scheduler

# Stop scheduler and queue worker before database rebuild
docker compose stop scheduler queue_worker

# Rollback helper function if healthcheck fails
rollback() {
  echo "HEALTHCHECK FAILED! Initiating automatic rollback..." >&2
  docker compose logs --tail=50 app >&2 || true
  if [ -n "$PREV_IMAGE" ]; then
    echo "Rolling back app, queue_worker, and scheduler to $PREV_IMAGE..." >&2
    API_TAG="$PREV_IMAGE" docker compose up -d --remove-orphans app queue_worker scheduler || true
    docker compose exec -T web nginx -s reload || true
  fi
  exit 1
}

trap 'rc=$?; [ $rc -ne 0 ] && echo "Deploy failed with exit code $rc" >&2; docker compose up -d app web queue_worker scheduler >/dev/null 2>&1 || true; exit $rc' EXIT
docker compose up -d postgres
docker compose up -d --remove-orphans app web

# Wait for php-fpm master
i=0
until docker compose exec -T app pgrep -f 'php-fpm: master' >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -ge 60 ] && { echo "Timeout waiting for php-fpm"; rollback; }
  sleep 2
done

# Reload nginx to resolve updated container IP
docker compose exec -T web nginx -s reload

# 2. Composer install, 3. Migrazioni, 4. Seeder delle traduzioni
docker compose exec -T app sh -c '
  set -e
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  php -d memory_limit=2048M artisan migrate --force
  php -d memory_limit=2048M artisan db:seed --class=TranslationSeeder --force
  php -d memory_limit=2048M artisan db:seed --class=FrontendTranslationSeeder --force
  php -d memory_limit=2048M artisan demo:reset
  php -d memory_limit=2048M artisan l5-swagger:generate
'

docker compose up -d queue_worker scheduler

# 5. Health check con rollback automatico se /up non risponde
echo "Running health check on /up..."
HEALTH_OK=0
for i in $(seq 1 15); do
  if docker compose exec -T web wget -q -O /dev/null --header 'Host: dev-api.fatturafacile.eu' http://127.0.0.1/up; then
    HEALTH_OK=1
    break
  fi
  sleep 2
done

if [ "$HEALTH_OK" -ne 1 ]; then
  rollback
fi

echo "dev deploy OK (${API_TAG:-dev})"
