<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Modules\Platform\Identity\Application\Passkeys\PasskeyException;
use Modules\Platform\Identity\Application\Passkeys\PasskeyService;

/**
 * Checks the answer to a second-factor challenge: a code of the authenticator app, an email code, a recovery code or
 * the assertion of a security key. Exactly one answer is checked, in that order of precedence (security key, email
 * code, app code, recovery code); the result names the method that was tried, so a failure is audited with it.
 */
final class TwoFactorChallengeVerifier
{
    public const REASON_INVALID = 'invalid_code';

    public const REASON_METHOD_UNAVAILABLE = 'method_unavailable';

    public function __construct(private readonly TwoFactorAuthentication $twoFactor) {}

    /**
     * @param  array{code?: string|null, recovery_code?: string|null, email_code?: string|null, sms_code?: string|null, webauthn?: array{ceremony_id?: string, credential?: array<string, mixed>}|null}  $input
     */
    public function verify(User $user, array $input): ChallengeResult
    {
        $webauthn = $input['webauthn'] ?? null;

        if (is_array($webauthn) && $webauthn !== []) {
            return $this->verifyWebAuthn($user, $webauthn);
        }

        $smsCode = $input['sms_code'] ?? null;

        if (is_string($smsCode) && $smsCode !== '') {
            $method = $this->twoFactor->hasSms($user)
                ? $this->twoFactor->verifyCode($user, null, null, null, $smsCode)
                : null;

            return $method === null
                ? ChallengeResult::failed(TwoFactorAuthentication::METHOD_SMS, $this->twoFactor->hasSms($user) ? self::REASON_INVALID : self::REASON_METHOD_UNAVAILABLE)
                : ChallengeResult::passed($method);
        }

        $emailCode = $input['email_code'] ?? null;

        if (is_string($emailCode) && $emailCode !== '') {
            $method = $this->twoFactor->hasEmail($user)
                ? $this->twoFactor->verifyCode($user, null, null, $emailCode)
                : null;

            return $method === null
                ? ChallengeResult::failed(TwoFactorAuthentication::METHOD_EMAIL, $this->twoFactor->hasEmail($user) ? self::REASON_INVALID : self::REASON_METHOD_UNAVAILABLE)
                : ChallengeResult::passed($method);
        }

        $code = $input['code'] ?? null;

        if (is_string($code) && $code !== '') {
            $method = $this->twoFactor->verifyCode($user, $code, null);

            return $method === null
                ? ChallengeResult::failed(TwoFactorAuthentication::METHOD_TOTP, $this->twoFactor->hasTotp($user) ? self::REASON_INVALID : self::REASON_METHOD_UNAVAILABLE)
                : ChallengeResult::passed($method);
        }

        $recoveryCode = $input['recovery_code'] ?? null;

        if (is_string($recoveryCode) && $recoveryCode !== '') {
            $method = $this->twoFactor->verifyCode($user, null, $recoveryCode);

            return $method === null
                ? ChallengeResult::failed(TwoFactorAuthentication::METHOD_RECOVERY_CODE, self::REASON_INVALID)
                : ChallengeResult::passed($method);
        }

        return ChallengeResult::failed(null, self::REASON_INVALID);
    }

    /**
     * @param  array{ceremony_id?: string, credential?: array<string, mixed>}  $webauthn
     */
    private function verifyWebAuthn(User $user, array $webauthn): ChallengeResult
    {
        $ceremonyId = $webauthn['ceremony_id'] ?? null;
        $credential = $webauthn['credential'] ?? null;

        if (! is_string($ceremonyId) || ! is_array($credential) || ! $this->twoFactor->isEnabled($user)) {
            return ChallengeResult::failed(TwoFactorAuthentication::METHOD_WEBAUTHN, self::REASON_METHOD_UNAVAILABLE);
        }

        try {
            app(PasskeyService::class)->finishSecondFactor($user, $ceremonyId, $credential);
        } catch (PasskeyException $e) {
            return ChallengeResult::failed(TwoFactorAuthentication::METHOD_WEBAUTHN, $e->reason);
        }

        return ChallengeResult::passed(TwoFactorAuthentication::METHOD_WEBAUTHN);
    }
}
