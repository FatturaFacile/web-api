<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\Enums;

/**
 * Lifecycle state of a Swiss VAT periodic return.
 */
enum SwissVatReturnStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Validated = 'validated';
    case Finalized = 'finalized';
    case Submitted = 'submitted';

    public function isLocked(): bool
    {
        return $this === self::Finalized || $this === self::Submitted;
    }
}
