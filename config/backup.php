<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backup archives
    |--------------------------------------------------------------------------
    |
    | `disk` receives the archives (`backups/{tenant_id}/{uuid}.zip`), `files_disk` is where the tenant files
    | (attachments, signatures) live, and `retention` is the number of completed backups kept per tenant.
    |
    */

    'disk' => env('BACKUP_DISK', 'private'),

    'files_disk' => env('BACKUP_FILES_DISK', 'local'),

    'retention' => (int) env('BACKUP_RETENTION', 7),

    // Safety snapshots taken before a restore are kept apart from the ordinary retention: this many are kept.
    'safety_retention' => (int) env('BACKUP_SAFETY_RETENTION', 3),

    // Disaster recovery targets reported by the backup status endpoint: a tenant whose last completed backup is older than
    // rpo_hours is reported as stale, and the restore time objective (rto_minutes) is informative only.
    'rpo_hours' => (int) env('BACKUP_RPO_HOURS', 24),

    'rto_minutes' => (int) env('BACKUP_RTO_MINUTES', 240),

    // Encryption at rest (AES-256-GCM, authenticated) of the archives. The key defaults to one derived from APP_KEY;
    // set BACKUP_ENCRYPTION_KEY to keep backups readable independently of it. Archives made before this setting stay restorable.
    'encryption' => [
        'enabled' => (bool) env('BACKUP_ENCRYPTION_ENABLED', true),
        'key' => env('BACKUP_ENCRYPTION_KEY'),
        'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_ENCRYPTION_PREVIOUS_KEYS', ''))))),
    ],

    // Schedule of `tenants:backup --all`: 'daily' (at daily_at) or 'hourly'.
    'schedule' => [
        'frequency' => env('BACKUP_SCHEDULE', 'daily'),
        'daily_at' => env('BACKUP_SCHEDULE_DAILY_AT', '02:00'),
        // Weekly restore drill of a sample tenant (`tenants:restore-drill`), and the stale/failing alert (`tenants:backup-check`).
        'drill_enabled' => (bool) env('BACKUP_DRILL_ENABLED', true),
        'check_hourly' => (bool) env('BACKUP_CHECK_ENABLED', true),
    ],

    // Log channel that receives the stale/failing backup alerts and failed restore drills (e.g. 'slack'); null = default.
    'alert_channel' => env('BACKUP_ALERT_CHANNEL'),

];
