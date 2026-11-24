<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum RetentionExpiryAction: string
{
    case HardDelete = 'hard_delete';
    case CryptographicAnonymize = 'cryptographic_anonymize';
    case ArchiveLock = 'archive_lock';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
