<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/**
 * A one-time code was asked for too often: one every minute and a few every hour per user.
 */
final class OtpThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many email codes requested.');
    }
}
