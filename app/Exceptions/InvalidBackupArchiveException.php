<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup archive that cannot be restored: unreadable, incomplete, tampered with or belonging to another tenant. The
 * message comes from `lang/en/backup.php`.
 */
class InvalidBackupArchiveException extends RuntimeException
{
    public const UNREADABLE_ARCHIVE = 'unreadable_archive';

    public const MISSING_MANIFEST = 'missing_manifest';

    public const INVALID_MANIFEST = 'invalid_manifest';

    public const TENANT_MISMATCH = 'tenant_mismatch';

    public const MISSING_DATA = 'missing_data';

    public const CHECKSUM_MISMATCH = 'checksum_mismatch';

    public const INVALID_DATA = 'invalid_data';

    public const UNKNOWN_TABLE = 'unknown_table';

    public const COUNT_MISMATCH = 'count_mismatch';

    public const UNSAFE_PATH = 'unsafe_path';

    public const MISSING_FILE = 'missing_file';

    public const MISSING_ARCHIVE = 'missing_archive';

    public const SNAPSHOT_FAILED = 'snapshot_failed';

    /**
     * @param  array<string, mixed>  $context  replacements of the message
     */
    public static function because(string $reason, array $context = []): self
    {
        return new self(__("backup.restore_errors.{$reason}", $context));
    }
}
