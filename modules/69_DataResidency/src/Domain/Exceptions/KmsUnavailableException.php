<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Exceptions;

/** The customer KMS/HSM could not be reached or refused the operation. */
final class KmsUnavailableException extends DataResidencyException
{
    public function httpStatus(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'KMS_UNAVAILABLE';
    }

    public function title(): string
    {
        return 'KMS Unavailable';
    }
}
