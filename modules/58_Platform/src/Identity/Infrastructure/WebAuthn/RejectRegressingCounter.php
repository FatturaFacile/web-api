<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\WebAuthn;

use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;

/**
 * Anti-cloning: the signature counter of an assertion must be greater than the stored one. Authenticators that never
 * count (synced passkeys always send zero) stay valid while both values are zero.
 */
final class RejectRegressingCounter implements CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        if ($currentCounter === 0 && $credentialRecord->counter === 0) {
            return;
        }

        if ($currentCounter <= $credentialRecord->counter) {
            throw CounterException::create($currentCounter, $credentialRecord->counter, 'The signature counter did not increase.');
        }
    }
}
