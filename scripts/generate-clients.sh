#!/usr/bin/env bash
#
# Generates the client SDKs of the FatturaFacile ERP API from the OpenAPI specification:
#   - TypeScript services and models for Angular -> clients/angular/erp-client
#   - Dart (dio) client and models for Flutter   -> clients/flutter/erp_client
#
# Usage:  scripts/generate-clients.sh [--refresh] [angular|flutter|all]
#   --refresh   regenerate docs/api/openapi.* first (php artisan docs:export)
#
# Requirements: Node.js 18+ (npx downloads @openapitools/openapi-generator-cli) and a Java 11+ runtime, which the
# generator needs. Pin the generator version with OPENAPI_GENERATOR_VERSION if you need reproducible output.

set -euo pipefail

cd "$(dirname "$0")/.."

SPEC="docs/api/openapi.yaml"
REFRESH=0
TARGET="all"

for argument in "$@"; do
    case "$argument" in
        --refresh) REFRESH=1 ;;
        angular|flutter|all) TARGET="$argument" ;;
        *) echo "Unknown argument: $argument" >&2; exit 2 ;;
    esac
done

if [ "$REFRESH" -eq 1 ]; then
    php -d memory_limit=2G artisan docs:export
fi

if [ ! -f "$SPEC" ]; then
    echo "Missing $SPEC: run 'php -d memory_limit=2G artisan docs:export' first." >&2
    exit 1
fi

GENERATOR="npx --yes @openapitools/openapi-generator-cli"

if [ "$TARGET" = "angular" ] || [ "$TARGET" = "all" ]; then
    $GENERATOR generate \
        -i "$SPEC" \
        -g typescript-angular \
        -o clients/angular/erp-client \
        --additional-properties=ngVersion=17,npmName=@erp/client,supportsES6=true
fi

if [ "$TARGET" = "flutter" ] || [ "$TARGET" = "all" ]; then
    $GENERATOR generate \
        -i "$SPEC" \
        -g dart-dio \
        -o clients/flutter/erp_client \
        --additional-properties=pubName=erp_client

    # dart-dio uses code generation (built_value, json_serializable) for its serializers.
    if command -v dart >/dev/null 2>&1; then
        (cd clients/flutter/erp_client && dart pub get && dart run build_runner build --delete-conflicting-outputs)
    else
        echo "Dart SDK not found: run 'dart pub get && dart run build_runner build --delete-conflicting-outputs' in clients/flutter/erp_client." >&2
    fi
fi

echo "Done. See docs/clients/AUTHENTICATION_GUIDE.md to wire authentication into the generated clients."