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

];
