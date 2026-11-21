<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Exceptions;

/** An outbound transfer to a non-adequate destination was blocked (HTTP 451 Unavailable For Legal Reasons). */
final class CrossBorderTransferBlockedException extends DataResidencyException
{
    public function httpStatus(): int
    {
        return 451;
    }

    public function errorCode(): string
    {
        return 'CROSS_BORDER_TRANSFER_BLOCKED';
    }

    public function title(): string
    {
        return 'Unavailable For Legal Reasons';
    }
}
