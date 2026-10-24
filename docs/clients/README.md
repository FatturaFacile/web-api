# Client SDKs

The API is described by an OpenAPI 3.1 specification generated from the real routes (`docs/api/openapi.yaml` and
`docs/api/openapi.json`). Client SDKs are generated from it, so they never drift from the API.

| Artifact | Purpose |
|----------|---------|
| `docs/api/openapi.yaml` / `openapi.json` | OpenAPI 3.1 specification (all endpoints, bearer + tenant security) |
| `docs/api/postman_collection.json` | Postman collection v2.1 (folders Auth, Invoicing, Banking, Accounting, Backups, ...) |
| `scripts/generate-clients.sh` | Generates the Angular and Flutter SDKs |
| `docs/clients/AUTHENTICATION_GUIDE.md` | Interceptors that add the token and tenant header and refresh the token |
| `GET /api/documentation` | Interactive documentation (Scalar) of the specification |

## Regenerating the documentation

```bash
php -d memory_limit=2G artisan docs:export
```

The command writes `openapi.json`, `openapi.yaml` and `postman_collection.json` into `docs/api`. Run it whenever routes,
requests or resources change, and commit the result. (It analyses the whole API: the extra memory is needed.)

Validate the specification with Spectral (the ruleset is `.spectral.yaml`):

```bash
npx @stoplight/spectral-cli lint docs/api/openapi.yaml
```

## Generating the SDKs

Requirements: Node.js 18+ and a Java 11+ runtime (used by OpenAPI Generator). For Flutter also the Dart SDK.

```bash
scripts/generate-clients.sh            # both SDKs
scripts/generate-clients.sh angular    # TypeScript services and models only
scripts/generate-clients.sh flutter    # Dart client and models only
scripts/generate-clients.sh --refresh  # regenerate the specification first
```

The commands the script runs:

```bash
# Angular 17+ (TypeScript services and models)
npx @openapitools/openapi-generator-cli generate -i docs/api/openapi.yaml -g typescript-angular \
    -o clients/angular/erp-client --additional-properties=ngVersion=17,npmName=@erp/client,supportsES6=true

# Flutter (Dart + dio client and models)
npx @openapitools/openapi-generator-cli generate -i docs/api/openapi.yaml -g dart-dio \
    -o clients/flutter/erp_client --additional-properties=pubName=erp_client
```

### Using the generated SDKs

* **Angular**: build the package (`cd clients/angular/erp-client && npm install && npm run build`), install it from
  `dist/` in the application (`npm install ../clients/angular/erp-client/dist`) and provide it with
  `provideApi('https://erp.example.com/api')` next to the interceptor of the authentication guide.
* **Flutter**: add the generated package as a path dependency (`erp_client: { path: ../clients/flutter/erp_client }`),
  run `dart run build_runner build --delete-conflicting-outputs` once, and create the client with the `Dio` instance
  that carries the interceptor of the authentication guide.

The generated folders (`clients/`) are build output: they are listed in `.gitignore` and are produced in CI or locally.

## Notes on the specification

* The specification is generated from the code. The paths are the real ones (for example invoices live under
  `/v1/sales/invoices`, payments under `/v1/sales/invoices/{id}/payments`, supplier invoices under
  `/v1/purchases/supplier-invoices`).
* Security: every operation except `POST /v1/auth/login`, the incoming webhooks and the documentation requires
  `BearerAuth` (the Sanctum token, documented as a bearer token) **and** `TenantHeader` (`X-Tenant-ID`).
* Errors are `application/problem+json`; validation errors are returned with status 422.