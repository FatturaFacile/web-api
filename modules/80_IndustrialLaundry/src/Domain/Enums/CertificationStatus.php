<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum CertificationStatus: string
{
    case Pending = 'pending';
    case Compliant = 'compliant';
    case NonCompliant = 'non_compliant';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
