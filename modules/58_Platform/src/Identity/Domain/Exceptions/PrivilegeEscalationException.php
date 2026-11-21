<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Exceptions;

use RuntimeException;

/** Thrown by the escalation guard; `$httpStatus` is 403 for escalation and 409 for the last-owner conflict. */
final class PrivilegeEscalationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 403)
    {
        parent::__construct($message);
    }
}
