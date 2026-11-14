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

> `route:cache` and `config:cache` must run after the environment file is final. Clear them
> (`php artisan optimize:clear`)
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

| Task                      | When                                                               |
|---------------------------|--------------------------------------------------------------------|
| `tenants:backup --all`    | daily 02:00 (retention: the 7 latest completed backups per tenant) |
| `subscriptions:process`   | daily 02:00                                                        |
| `invoices:send-reminders` | daily 08:00                                                        |
| `ecommerce:sync-orders`   | every 15 minutes                                                   |

### Statutory retention of automatic cleanups

The cleanups that delete or archive records skip the records of a statutory class while their period runs. The
periods live in `App\Services\Privacy\StatutoryRetentionCatalogue`. They are the ones the code already applies, are
not verified against the official texts and must be confirmed by the accountant or counsel.

| Class                  | Records                                                  | Kept for           | Legal basis already in code                     |
|------------------------|----------------------------------------------------------|--------------------|-------------------------------------------------|
| `fiscal_record`        | invoices, ledger books, payroll vouchers                 | 10 years           | Swiss CO art. 958f, Italian c.c. art. 2220      |
| `fiscal_record`        | contracts                                                | 10 years           | Italian c.c. art. 2946 (cautious choice)        |
| `statutory_employment` | working time records                                     | 5 years minimum    | ArG art. 46, ArGV 1 art. 73, OLL 1 art. 73      |
| `evidence`             | consent proof, audit trails                              | held, no period    | GDPR art. 7(1), accountability                  |

- Periods count from the end of the financial year of the record date, taken as the calendar year until the accountant
  confirms otherwise: a payslip of March 2026 is kept until 31 December 2036.
- Document types match case-insensitively and by their Italian and English names (`Payslip`, `busta paga`, `cedolino`,
  `fattura`, `contratto`, ...).
- `dms:retention` skips documents of those types until the end of the period counted from their creation, moves their
  retention date to that day and logs `document_retention_held_statutory` once.
- `hr:purge-employee-documents` does the same for `payslip` and `contract` documents, counting from their issue date or,
  without one, from their creation.
- A delete policy shorter than 120 months on a statutory document type, or attached to a document of such a type, is
  refused, and so is a retention date before the end of the period on a payslip or a contract of the personnel file.
- `time:purge-expired` never goes below the 5 year minimum, whatever the company setting; it removes whole calendar
  years only, so records are kept between 5 and 6 years.
- A record without a creation date is kept.
- `demo:reset` refuses to run with `APP_ENV=production`, even when `DEMO_RESET_ENABLED=true`.
- Backups, the outbox archive and the other employee document types are not covered yet (open questions).

## 5. Storage and backups

- [ ] `storage/` and `bootstrap/cache/` writable by the web and worker user ✔
- [ ] The disk of `BACKUP_DISK` is included in the infrastructure backup and monitored for free space
- [ ] Take a manual backup after deploying and download it once (`POST /api/v1/system/backups`, then `GET .../download`)
- [ ] Rehearse a restore on a staging copy (`POST /api/v1/system/backups/{id}/restore`): a safety snapshot
  (`pre-restore-safety-*.zip`) is created automatically before every restore; the last 3 are kept

## 6. Smoke test after deploy

- [ ] `POST /api/v1/auth/login` returns a token and the active tenant
- [ ] `GET /api/v1/dashboard/layout` with the token and `X-Tenant-Id` answers 200
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
