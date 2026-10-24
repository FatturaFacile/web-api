# Production checklist — release v1.0.0

Run `php artisan release:check` on the server at the end of this list: it verifies the points marked with ✔ and exits
with a failure when one of them is wrong.

## 1. Environment

- [ ] `APP_ENV=production`, `APP_DEBUG=false` ✔, `APP_KEY` generated (`php artisan key:generate --force`) ✔
- [ ] `APP_URL` is the public HTTPS URL (it is written in the OpenAPI servers and the Postman variables)
- [ ] `APP_RELEASE=1.0.0` (optional: defaults to the version in `config/release.php`)
- [ ] Database credentials, `DB_CONNECTION` is MySQL/MariaDB or PostgreSQL (not SQLite)
- [ ] `QUEUE_CONNECTION=database` or `redis` — never `sync` in production ✔
- [ ] `CACHE_STORE=redis` (or `database`): the cache must be shared by web, queue and scheduler processes ✔
- [ ] `BACKUP_DISK=private` (local disk `storage/app/private`) or an S3 disk; `BACKUP_FILES_DISK=local`
- [ ] `BACKUP_RETENTION=7` and `BACKUP_SAFETY_RETENTION=3` (defaults)
- [ ] `DOCS_ENABLED=false` if the interactive documentation (`/api/documentation`) must not be public
- [ ] `SANCTUM_STATEFUL_DOMAINS` / CORS configured for the Angular and Flutter origins
- [ ] Mail, SDI provider, e-commerce and fiscal printer credentials set in the environment

## 2. Deploy

```bash
git fetch --tags && git checkout v1.0.0
composer install --no-dev --optimize-autoloader
php artisan down --retry=60
php artisan migrate --force          # migrations are additive: safe to run on a populated database
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan event:cache
php artisan queue:restart            # workers pick up the new code
php artisan up
php artisan release:check
```

> `route:cache` and `config:cache` must run after the environment file is final. Clear them (`php artisan optimize:clear`)
> before running tests on the same machine.

## 3. Queue workers (Supervisor)

Backups, e-commerce stock pushes and e-mails run on the queue. `/etc/supervisor/conf.d/erp-worker.conf`:

```ini
[program:erp-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/erp/artisan queue:work --queue=default,backups --tries=3 --timeout=900 --sleep=3 --max-time=3600
directory=/var/www/erp
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/supervisor/erp-worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start "erp-worker:*"
```

`--timeout=900` matches the timeout of `GenerateTenantBackupJob`; keep the Supervisor `stopwaitsecs` above it so a
backup is not cut short by a restart. Run `php artisan queue:restart` after every deploy.

## 4. Scheduler (cron)

One system cron entry runs every scheduled task of the application:

```cron
* * * * * cd /var/www/erp && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks (`php artisan schedule:list`):

| Task | When |
|------|------|
| `tenants:backup --all` | daily 02:00 (retention: the 7 latest completed backups per tenant) |
| `subscriptions:process` | daily 02:00 |
| `invoices:send-reminders` | daily 08:00 |
| `ecommerce:sync-orders` | every 15 minutes |

## 5. Storage and backups

- [ ] `storage/` and `bootstrap/cache/` writable by the web and worker user ✔
- [ ] The disk of `BACKUP_DISK` is included in the infrastructure backup and monitored for free space
- [ ] Take a manual backup after deploying and download it once (`POST /api/v1/system/backups`, then `GET .../download`)
- [ ] Rehearse a restore on a staging copy (`POST /api/v1/system/backups/{id}/restore`): a safety snapshot
  (`pre-restore-safety-*.zip`) is created automatically before every restore; the last 3 are kept

## 6. Smoke test after deploy

- [ ] `POST /api/v1/auth/login` returns a token and the active tenant
- [ ] `GET /api/v1/dashboard/layout` with the token and `X-Tenant-ID` answers 200
- [ ] `GET /api/documentation` shows the documentation (or answers 404 when `DOCS_ENABLED=false`)
- [ ] `php artisan schedule:list` shows `tenants:backup --all`
- [ ] A test job reaches the worker: `php artisan queue:work --once` or watch `/var/log/supervisor/erp-worker.log`

## 7. Tag the release

```bash
git tag -a v1.0.0 -m "Release v1.0.0 - Complete Multi-Tenant ERP Core"
git push origin v1.0.0
```

Move the `## [Unreleased]` entries of `CHANGELOG.md` under the new version before tagging, and bump
`APP_RELEASE` / `config/release.php` for the next release.