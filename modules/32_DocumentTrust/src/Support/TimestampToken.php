<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Support;

use DateTimeImmutable;

/**
 * What a time-stamp provider returns: its name, the token it issued (null when the provider issues none), the time it
 * states and whether it is a qualified time stamp.
 */
final readonly class TimestampToken
{
    public function __construct(
        public string $provider,
        public ?string $token,
        public DateTimeImmutable $issuedAt,
        public bool $qualified,
    ) {}
}
