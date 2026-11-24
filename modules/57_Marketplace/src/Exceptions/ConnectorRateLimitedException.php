<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * The rate limit of an installation is used up for the current minute.
 */
final class ConnectorRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(__('marketplace_errors.sync.rate_limited', ['seconds' => $retryAfterSeconds]));
    }
}
