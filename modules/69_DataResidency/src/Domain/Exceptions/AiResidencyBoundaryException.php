<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Exceptions;

/** An AI/OCR or vector request would leave the sovereign nodes bound to the policy. */
final class AiResidencyBoundaryException extends DataResidencyException
{
    public function httpStatus(): int
    {
        return 451;
    }

    public function errorCode(): string
    {
        return 'AI_RESIDENCY_BOUNDARY';
    }

    public function title(): string
    {
        return 'AI Residency Boundary Violation';
    }
}
