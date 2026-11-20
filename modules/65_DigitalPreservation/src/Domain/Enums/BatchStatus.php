<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Enums;

/**
 * Lifecycle of a preservation batch (PdV to PdA).
 */
enum BatchStatus: string
{
    case Draft = 'draft';
    case Sealed = 'sealed';
    case Timestamped = 'timestamped';
    case Dispatched = 'dispatched';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    /**
     * Whether the content of the batch is frozen.
     */
    public function isFrozen(): bool
    {
        return $this !== self::Draft;
    }
}
