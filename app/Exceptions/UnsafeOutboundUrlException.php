<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A URL or host the server refuses to reach: malformed, not http(s), or resolving to the local machine, a private
 * network, cloud metadata or another non-public address. The message never carries the resolved addresses.
 */
class UnsafeOutboundUrlException extends RuntimeException
{
    public const INVALID_URL = 'invalid_url';

    public const INVALID_HOST = 'invalid_host';

    public const UNRESOLVABLE_HOST = 'unresolvable_host';

    public const NON_PUBLIC_ADDRESS = 'non_public_address';

    public function __construct(public readonly string $reason, public readonly string $host = '')
    {
        parent::__construct(match ($reason) {
            self::INVALID_URL => 'The URL is not a valid http or https address.',
            self::UNRESOLVABLE_HOST => 'The host name does not resolve.',
            self::NON_PUBLIC_ADDRESS => 'The host does not point to a public address.',
            default => 'The host name is not valid.',
        });
    }
}
