# Local Docker Trial (`deploy/local`)

Minimal stack to try FatturaFacile locally from the published image (`docker.io/fatturafacile/api:0.1.2`).
Runs over **HTTPS** on `https://localhost:8080`.

## Quick Start

```bash
cp deploy/local/env.example deploy/local/.env
docker compose -f deploy/local/compose.yaml up -d --wait
docker compose -f deploy/local/compose.yaml exec app php artisan migrate --force
docker compose -f deploy/local/compose.yaml exec app php artisan erp:bootstrap
```

Check: `curl -k https://localhost:8080/up` (returns 200).
Swagger UI: https://localhost:8080/api/documentation
OpenAPI JSON: https://localhost:8080/docs/api-docs.json

## TLS with mkcert (Browser-trusted)

By default, the `web` container auto-generates a self-signed certificate in `deploy/local/certs/` if none exists.
To avoid browser security warnings, you can install trusted certificates
using [mkcert](https://github.com/FiloSottile/mkcert):

```bash
# 1. Install mkcert local CA (first time only)
mkcert -install

# 2. Generate certificates into deploy/local/certs/
mkcert -cert-file deploy/local/certs/localhost.crt -key-file deploy/local/certs/localhost.key localhost 127.0.0.1 ::1

# 3. Reload nginx
docker compose -f deploy/local/compose.yaml exec web nginx -s reload
```
