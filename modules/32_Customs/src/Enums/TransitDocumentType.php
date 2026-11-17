<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * The common transit document: T1 for non-Union goods, T2 for Union goods, none when the declaration is not in transit.
 */
enum TransitDocumentType: string
{
    case None = 'none';
    case T1 = 'T1';
    case T2 = 'T2';

    public function isTransit(): bool
    {
        return $this !== self::None;
    }
}
