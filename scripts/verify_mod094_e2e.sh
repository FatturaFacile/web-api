#!/usr/bin/env bash
# Mod 094 (AI cash flow and demand forecasting): end-to-end verification on a fresh SQLite database.
#
#   scripts/verify_mod094_e2e.sh [path-to-web-frontend]
#
# Rebuilds a throw-away SQLite file, runs the AiForecastingDemoSeeder and the forecasting:verify-e2e checks (payment model
# training, 13-week and 12-month liquidity with the CH/IT statutory calendar, what-if, SKU demand, accuracy, one-click
# apply and tenant isolation). With a web-frontend path it also runs `npm run build` there. Never touches PostgreSQL.
set -euo pipefail

cd "$(dirname "$0")/.."

FRONTEND="${1:-}"
DB_FILE="$(mktemp -d)/mod094-e2e.sqlite"
touch "$DB_FILE"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_FILE"
export DB_FOREIGN_KEYS=true

ARGS=(--env=testing)
if [[ -n "$FRONTEND" ]]; then
  ARGS+=("--frontend=$FRONTEND")
fi

trap 'rm -f "$DB_FILE"' EXIT

php artisan config:clear --env=testing >/dev/null
php artisan forecasting:verify-e2e "${ARGS[@]}"
