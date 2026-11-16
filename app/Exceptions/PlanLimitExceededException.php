<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * The subscription plan of the tenant does not allow one more user or custom role.
 */
final class PlanLimitExceededException extends DomainException
{
    public function __construct(public readonly string $limit, public readonly int $max)
    {
        parent::__construct("The subscription plan allows at most {$max} {$limit}.");
    }
}
