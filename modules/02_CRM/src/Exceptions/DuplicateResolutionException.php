<?php

declare(strict_types=1);

namespace Modules\CRM\Exceptions;

use RuntimeException;

/**
 * A duplicate candidate cannot be resolved: already resolved (409) or not a lead-to-lead match (422).
 */
final class DuplicateResolutionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function alreadyResolved(): self
    {
        return new self('This duplicate has already been resolved.', 409);
    }

    public static function notMergeable(string $reason): self
    {
        return new self($reason, 422);
    }
}
