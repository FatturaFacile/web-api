# Dev environment

`dev` branch → https://dev.fatturafacile.eu (frontend + same-origin API) and https://dev-api.fatturafacile.eu (API only).
The same stack also serves https://inglorious.fatturafacile.eu: the Inglorious frontend (repo `web-frontend-inglorious`,
build in `~/fatturafacile-dev/inglorious`) with the same-origin dev API.

- Runs on the production VPS in `~aledv/fatturafacile-dev`, fully separate from production: own containers (`ffdev_*`),
  network, Postgres volume and `.env`. Only `ffdev_web` joins the production network so that `erp_nginx` can reach it.
- `.github/workflows/deploy-dev.yml` (here and in web-frontend): push to `dev` → CI → image `ghcr.io/fatturafacile/web-api:dev`
  (+ `sha-<commit>`) → `deploy.sh` over SSH. The frontend workflow uploads the `dev` build into `~/fatturafacile-dev/fe`.
- The dev DB and storage are rebuilt from scratch with demo data (`demo:reset`) on every deploy and every 30 minutes
  (scheduler), so no real data stays on dev. Requires `DEMO_RESET_ENABLED=true` in `.env`.
- Secrets: GitHub Environment `dev` (`DEV_SSH_HOST`, `DEV_SSH_USER`, `DEV_SSH_KEY`, `DEV_SSH_KNOWN_HOSTS`);
  app settings in `~/fatturafacile-dev/.env` on the server (template: `env.example`).
- TLS: certificates `dev.fatturafacile.eu` (dev + dev-api) and `inglorious.fatturafacile.eu` from the production certbot; `edge-dev.conf` is the vhost
  installed in the production nginx `conf.d` as `dev.conf`.

Manual redeploy on the server: `cd ~/fatturafacile-dev && ./deploy.sh` (optionally `API_TAG=sha-xxxxxxx`).
