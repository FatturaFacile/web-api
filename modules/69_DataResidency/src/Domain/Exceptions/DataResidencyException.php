<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Exceptions;

use RuntimeException;

/**
 * Base of every rule violation of the residency and sovereignty module. The HTTP status and the machine readable error
 * code travel with the exception so the controllers and the middleware answer uniformly.
 */
class DataResidencyException extends RuntimeException
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'DATA_RESIDENCY_RULE_VIOLATION';
    }

    public function title(): string
    {
        return 'Data Residency Rule Violation';
    }
}
