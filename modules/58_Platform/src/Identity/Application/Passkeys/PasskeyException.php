<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application\Passkeys;

use RuntimeException;

/** A passkey ceremony that cannot continue; `$reason` is a stable code clients can translate. */
final class PasskeyException extends RuntimeException
{
    public const EXPIRED = 'ceremony_expired';

    public const INVALID = 'assertion_invalid';

    public const CLONED = 'authenticator_cloned';

    public const REVOKED = 'credential_revoked';

    public const UNKNOWN = 'credential_unknown';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $message);
    }
}
