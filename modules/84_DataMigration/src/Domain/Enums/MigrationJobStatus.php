<?php

declare(strict_types=1);

namespace Modules\DataMigration\Domain\Enums;

enum MigrationJobStatus: string
{
    case Uploaded = 'Uploaded';
    case Mapped = 'Mapped';
    case DryRunValidated = 'DryRunValidated';
    case Executing = 'Executing';
    case Completed = 'Completed';
    case RolledBack = 'RolledBack';
    case Failed = 'Failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
