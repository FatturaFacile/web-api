<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use DateTimeImmutable;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Support\TimestampToken;

/**
 * Reads the clock of the server. It issues NO token and is NOT a trusted or qualified time stamp: the package says so
 * (`timestamp_qualified` false, `timestamp_token` empty). Replace it with a qualified provider for legal value.
 */
final class LocalClockTimestampProvider implements TrustedTimestampProvider
{
    /**
     * Provider name.
     */
    public function name(): string
    {
        return 'local-clock';
    }

    /**
     * The server time, with no token.
     */
    public function timestamp(string $sha256Hex): TimestampToken
    {
        return new TimestampToken($this->name(), null, new DateTimeImmutable, false);
    }
}
