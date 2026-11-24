<?php

declare(strict_types=1);

namespace App\Services\Auth\Scim;

use RuntimeException;

/**
 * A SCIM request that cannot be honoured, with the HTTP status and the `scimType` of RFC 7644 section 3.12.
 */
final class ScimException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $scimType, string $detail)
    {
        parent::__construct($detail);
    }
}
