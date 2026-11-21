<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Exceptions;

/** A node outside the jurisdiction zone was bound to the policy. */
final class CrossBorderResidencyViolationException extends DataResidencyException
{
    public function errorCode(): string
    {
        return 'CROSS_BORDER_RESIDENCY_VIOLATION';
    }

    public function title(): string
    {
        return 'Cross-Border Residency Violation';
    }
}
