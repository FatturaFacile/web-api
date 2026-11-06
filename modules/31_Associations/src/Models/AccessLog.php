<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use LogicException;
use Modules\Associations\Enums\AccessReason;

/**
 * Every attempt at a reader. Append-only: a database trigger refuses updates and deletions.
 */
final class AccessLog extends AssociationEntry
{
    public const UPDATED_AT = null;

    protected $table = 'assoc_access_logs';

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('The access log cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('The access log cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['reason_code' => AccessReason::class, 'occurred_at' => 'datetime'];
    }
}
