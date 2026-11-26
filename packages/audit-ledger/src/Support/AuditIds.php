<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use Illuminate\Support\Str;

final class AuditIds
{
    public const string NIL_TENANT = '00000000-0000-0000-0000-000000000000';

    /**
     * The resource id as a UUID: a UUID stays as it is, any other identifier (integer key, composite reference) becomes a
     * stable name-based UUID, so the same record always maps to the same value.
     */
    public static function uuidFor(string $value): string
    {
        if (Str::isUuid($value)) {
            return strtolower($value);
        }

        $hex = md5($value);

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec($hex[16]) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }
}
