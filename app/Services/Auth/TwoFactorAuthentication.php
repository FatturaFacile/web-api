<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Security\TenantSecurityPolicies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Identity\Application\Passkeys\PasskeyService;

/**
 * Two-factor authentication of the users with an authenticator app (TOTP), an email one-time code, a security key or
 * passkey (WebAuthn) and single-use recovery codes.
 *
 * A user is "enrolled" when at least one of the two enrollable factors, the authenticator app or the email code, is
 * confirmed (isEnabled). Security keys answer the challenge of an enrolled user as a further method; they do not enrol
 * the user on their own, so a user who only uses passkeys for passwordless login is not challenged after the password.
 *
 * Setup has two steps, so a secret the user never scanned cannot lock them out: enable stores a pending secret, confirm
 * activates it with a first valid code. At login a user with two factors gets a short-lived challenge token instead of
 * an access token; the token is exchanged for an access token with a code. Recovery codes are kept as SHA-256 hashes:
 * they carry 50 random bits each, so a slow hash adds nothing.
 */
final class TwoFactorAuthentication
{
    public const METHOD_TOTP = 'totp';

    public const METHOD_EMAIL = 'email';

    public const METHOD_WEBAUTHN = 'webauthn';

    public const METHOD_RECOVERY_CODE = 'recovery_code';

    /** Not a factor of the challenge: the login that skipped it because the device is remembered. */
    public const METHOD_TRUSTED_DEVICE = 'trusted_device';

    private const CHALLENGE_CACHE_PREFIX = 'auth:two-factor-challenge:';

    private const RECOVERY_CODE_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public const METHOD_SMS = 'sms';

    public function __construct(private readonly Totp $totp, private readonly SecondFactorCodes $codes) {}

    /**
     * Whether the user has at least one enrolled factor (authenticator app, email code, SMS code, or a registered
     * security key or passkey): the password login asks for the second step, the tenant policies are met, recovery
     * codes exist. A passwordless passkey login does not use this: the passkey itself verifies the user.
     */
    public function isEnabled(User $user): bool
    {
        return $this->hasTotp($user) || $this->hasEmail($user) || $this->hasSms($user) || $this->hasWebAuthn($user);
    }

    public function hasSms(User $user): bool
    {
        return $user->two_factor_sms_confirmed_at !== null && is_string($user->two_factor_sms_phone) && $user->two_factor_sms_phone !== '';
    }

    /**
     * Whether the user keeps an enrolled factor other than the given one (the one being removed). A security key
     * counts as one factor however many credentials the user holds; removing a single credential is checked by the
     * caller against the credential count.
     *
     * @param  self::METHOD_TOTP|self::METHOD_EMAIL|self::METHOD_SMS|self::METHOD_WEBAUTHN  $except
     */
    public function otherFactorsExist(User $user, string $except): bool
    {
        return ($except !== self::METHOD_TOTP && $this->hasTotp($user))
            || ($except !== self::METHOD_EMAIL && $this->hasEmail($user))
            || ($except !== self::METHOD_SMS && $this->hasSms($user))
            || ($except !== self::METHOD_WEBAUTHN && $this->hasWebAuthn($user));
    }

    /**
     * Whether the iam.mandatory_mfa setting is on and the user holds a role that must keep two-factor authentication
     * (their last factor cannot be removed).
     */
    public function mustKeepTwoFactor(User $user): bool
    {
        if (config('iam.mandatory_mfa') !== true) {
            return false;
        }

        $policies = app(TenantSecurityPolicies::class);

        return $user->tenants()->get()->contains(
            fn (Tenant $tenant): bool => $policies->mandatoryFor(data_get($tenant->getRelations()['pivot'] ?? null, 'role')),
        );
    }

    /**
     * Called after a passkey was revoked: the factors changed, so the trusted devices end, and a user left without any
     * factor loses the recovery codes that belonged to them.
     */
    public function afterPasskeyRemoved(User $user): void
    {
        if (! $this->isEnabled($user) && $this->remainingRecoveryCodes($user) > 0) {
            $user->forceFill(['two_factor_recovery_codes' => null])->save();
        }

        app(TrustedDevices::class)->revokeAll($user);
    }

    /**
     * Creates the recovery codes when the user has none and returns them (shown only once), otherwise null.
     *
     * @return list<string>|null
     */
    public function ensureRecoveryCodes(User $user): ?array
    {
        if ($this->remainingRecoveryCodes($user) > 0) {
            return null;
        }

        return $this->regenerateRecoveryCodes($user);
    }

    public function hasTotp(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    public function hasEmail(User $user): bool
    {
        return $user->two_factor_email_confirmed_at !== null;
    }

    public function hasWebAuthn(User $user): bool
    {
        return app(PasskeyService::class)->hasCredentials($user);
    }

    /**
     * The methods the user can answer a challenge with, in the order the client should offer them. Empty for a user
     * without an enrolled factor.
     *
     * @return list<self::METHOD_*>
     */
    public function availableMethods(User $user): array
    {
        if (! $this->isEnabled($user)) {
            return [];
        }

        $methods = [];

        if ($this->hasTotp($user)) {
            $methods[] = self::METHOD_TOTP;
        }

        if ($this->hasEmail($user)) {
            $methods[] = self::METHOD_EMAIL;
        }

        if ($this->hasSms($user)) {
            $methods[] = self::METHOD_SMS;
        }

        if ($this->hasWebAuthn($user)) {
            $methods[] = self::METHOD_WEBAUTHN;
        }

        if ($this->remainingRecoveryCodes($user) > 0) {
            $methods[] = self::METHOD_RECOVERY_CODE;
        }

        return $methods;
    }

    /**
     * Whether the tenant requires every member to use two factors (tenant setting `security.require_two_factor`).
     */
    public function isRequiredBy(?Tenant $tenant): bool
    {
        return (bool) data_get($tenant?->settings, 'security.require_two_factor', false);
    }

    /**
     * Sets the tenant requirement; the tenant row is locked, so a concurrent change to other settings is not lost.
     */
    public function setRequiredBy(string $tenantId, bool $required): Tenant
    {
        return DB::transaction(function () use ($tenantId, $required): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $settings = (array) ($tenant->settings ?? []);
            data_set($settings, 'security.require_two_factor', $required);
            $tenant->settings = $settings;
            $tenant->save();

            return $tenant;
        });
    }

    /**
     * Stores a new pending secret, replacing any earlier pending one.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function beginSetup(User $user): array
    {
        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            // The recovery codes of a user who already has the email factor stay valid.
            'two_factor_recovery_codes' => $this->otherFactorsExist($user, self::METHOD_TOTP) ? $user->two_factor_recovery_codes : null,
            'two_factor_last_used_step' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->provisioningUri($secret, $user->email, (string) config('auth.two_factor.issuer')),
        ];
    }

    /**
     * Activates the pending secret with a valid code and returns the recovery codes, shown only this once.
     *
     * @return list<string>|null null when the code does not match
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        $step = $this->totp->verify($user->two_factor_secret, $code);

        if ($step === null) {
            return null;
        }

        [$plain, $hashes] = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $step,
            'two_factor_recovery_codes' => $hashes,
        ])->save();

        return $plain;
    }

    /**
     * Removes the authenticator app. The email factor, when the user has it, stays together with the recovery codes.
     * Trusted devices were granted under the old set of factors, so they end.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => $this->otherFactorsExist($user, self::METHOD_TOTP) ? $user->two_factor_recovery_codes : null,
            'two_factor_last_used_step' => null,
        ])->save();

        app(TrustedDevices::class)->revokeAll($user);
    }

    /**
     * Removes every factor of the user (reset by an administrator): the authenticator app, the email code, the
     * recovery codes and the trusted devices. Security keys are managed on their own and stay.
     */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_email_confirmed_at' => null,
            'two_factor_sms_phone' => null,
            'two_factor_sms_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        $this->codes->forget($user);
        app(TrustedDevices::class)->revokeAll($user);
    }

    /**
     * Activates the email factor after a code sent to the address of the user was entered. The recovery codes are
     * created when the user has none yet and returned (shown only this once), otherwise null.
     *
     * @return list<string>|null
     */
    public function confirmEmail(User $user, string $code): ?array
    {
        if ($this->hasEmail($user) || ! $this->codes->verify($user, SecondFactorCodes::PURPOSE_ENROL, $code)) {
            return null;
        }

        $plain = null;
        $attributes = ['two_factor_email_confirmed_at' => now()];

        if ($this->remainingRecoveryCodes($user) === 0) {
            [$plain, $hashes] = $this->newRecoveryCodes();
            $attributes['two_factor_recovery_codes'] = $hashes;
        }

        $user->forceFill($attributes)->save();

        return $plain ?? [];
    }

    /**
     * Removes the email factor; without another factor the recovery codes and trusted devices go too.
     */
    public function disableEmail(User $user): void
    {
        $attributes = ['two_factor_email_confirmed_at' => null];

        if (! $this->otherFactorsExist($user, self::METHOD_EMAIL)) {
            $attributes['two_factor_recovery_codes'] = null;
        }

        $user->forceFill($attributes)->save();

        $this->codes->forget($user, SecondFactorCodes::CHANNEL_EMAIL);
        app(TrustedDevices::class)->revokeAll($user);
    }

    /**
     * Activates the SMS factor after the code sent to the number being enrolled was entered; the number is stored
     * (encrypted) with the factor. Recovery codes are created when the user has none, as for the email factor.
     *
     * @return list<string>|null null when there is no pending number or the code is wrong
     */
    public function confirmSms(User $user, string $code): ?array
    {
        $phone = $this->codes->pendingPhone($user);

        if ($this->hasSms($user) || $phone === null || ! $this->codes->verify($user, SecondFactorCodes::PURPOSE_ENROL, $code, SecondFactorCodes::CHANNEL_SMS)) {
            return null;
        }

        $plain = null;
        $attributes = ['two_factor_sms_phone' => $phone, 'two_factor_sms_confirmed_at' => now()];

        if ($this->remainingRecoveryCodes($user) === 0) {
            [$plain, $hashes] = $this->newRecoveryCodes();
            $attributes['two_factor_recovery_codes'] = $hashes;
        }

        $user->forceFill($attributes)->save();

        return $plain ?? [];
    }

    /**
     * Removes the SMS factor and its number; without another factor the recovery codes and trusted devices go too.
     */
    public function disableSms(User $user): void
    {
        $attributes = ['two_factor_sms_phone' => null, 'two_factor_sms_confirmed_at' => null];

        if (! $this->otherFactorsExist($user, self::METHOD_SMS)) {
            $attributes['two_factor_recovery_codes'] = null;
        }

        $user->forceFill($attributes)->save();

        $this->codes->forget($user, SecondFactorCodes::CHANNEL_SMS);
        app(TrustedDevices::class)->revokeAll($user);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        [$plain, $hashes] = $this->newRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();

        return $plain;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) ($user->two_factor_recovery_codes ?? []));
    }

    /**
     * Checks a code of the app or a recovery code. The user row is locked, so two requests cannot both use the same
     * code: the accepted time step and the consumed recovery code are saved before the lock is released.
     *
     * The email code is checked first, apart from the transaction: it lives in the cache with its own lock.
     *
     * @return self::METHOD_*|null
     */
    public function verifyCode(User $user, ?string $code, ?string $recoveryCode, ?string $emailCode = null, ?string $smsCode = null): ?string
    {
        if (is_string($code) && $code !== '') {
            // The authenticator app code is checked below.
        } elseif (is_string($smsCode) && $smsCode !== '') {
            return $this->hasSms($user) && $this->codes->verify($user, SecondFactorCodes::PURPOSE_VERIFY, $smsCode, SecondFactorCodes::CHANNEL_SMS)
                ? self::METHOD_SMS
                : null;
        } elseif (is_string($emailCode) && $emailCode !== '') {
            return $this->hasEmail($user) && $this->codes->verify($user, SecondFactorCodes::PURPOSE_VERIFY, $emailCode)
                ? self::METHOD_EMAIL
                : null;
        }

        return DB::transaction(function () use ($user, $code, $recoveryCode): ?string {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->isEnabled($locked)) {
                return null;
            }

            if (is_string($code) && $code !== '') {
                if (! $this->hasTotp($locked)) {
                    return null;
                }

                $step = $this->totp->verify((string) $locked->two_factor_secret, $code, $locked->two_factor_last_used_step);

                if ($step === null) {
                    return null;
                }

                $locked->forceFill(['two_factor_last_used_step' => $step])->save();
                $user->setRawAttributes($locked->getAttributes(), true);

                return self::METHOD_TOTP;
            }

            if (is_string($recoveryCode) && $recoveryCode !== '') {
                $hash = $this->hashRecoveryCode($recoveryCode);
                $hashes = array_values((array) ($locked->two_factor_recovery_codes ?? []));
                $index = null;

                foreach ($hashes as $position => $stored) {
                    if (is_string($stored) && hash_equals($stored, $hash)) {
                        $index = $position;
                    }
                }

                if ($index === null) {
                    return null;
                }

                unset($hashes[$index]);
                $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                $user->setRawAttributes($locked->getAttributes(), true);

                return self::METHOD_RECOVERY_CODE;
            }

            return null;
        });
    }

    /**
     * A login challenge for a user who passed the password check. Only the hash of the token is a cache key.
     */
    public function createChallenge(User $user, ?string $tenantId, string $authMethod = 'password', bool $tokenMode = false): string
    {
        $token = Str::random(64);

        Cache::put($this->challengeKey($token), [
            'user_id' => (string) $user->getKey(),
            'tenant_id' => $tenantId,
            'failed_attempts' => 0,
            'auth_method' => $authMethod,
            'token_mode' => $tokenMode,
        ], now()->addMinutes($this->challengeTtlMinutes()));

        return $token;
    }

    /**
     * @return array{user_id: string, tenant_id: string|null, failed_attempts: int, auth_method?: string, token_mode?: bool}|null
     */
    public function findChallenge(string $token): ?array
    {
        $challenge = Cache::get($this->challengeKey($token));

        return is_array($challenge) ? $challenge : null;
    }

    /**
     * Counts a wrong code; after the configured number of failures the challenge is gone and the user logs in again.
     *
     * @return bool true when this failure used the last attempt and the challenge was dropped
     */
    public function recordFailedAttempt(string $token): bool
    {
        $challenge = $this->findChallenge($token);

        if ($challenge === null) {
            return false;
        }

        $challenge['failed_attempts']++;

        if ($challenge['failed_attempts'] >= (int) config('auth.two_factor.max_challenge_attempts')) {
            $this->forgetChallenge($token);

            return true;
        }

        Cache::put($this->challengeKey($token), $challenge, now()->addMinutes($this->challengeTtlMinutes()));

        return false;
    }

    public function forgetChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    public function challengeTtlMinutes(): int
    {
        return max(1, (int) config('auth.two_factor.challenge_ttl_minutes'));
    }

    /**
     * @return array{0: list<string>, 1: list<string>} the plain codes and their hashes
     */
    private function newRecoveryCodes(): array
    {
        $plain = [];

        for ($index = 0; $index < (int) config('auth.two_factor.recovery_codes'); $index++) {
            $plain[] = $this->randomRecoveryChunk().'-'.$this->randomRecoveryChunk();
        }

        return [$plain, array_map(fn (string $code): string => $this->hashRecoveryCode($code), $plain)];
    }

    private function randomRecoveryChunk(): string
    {
        $chunk = '';

        for ($index = 0; $index < 5; $index++) {
            $chunk .= self::RECOVERY_CODE_ALPHABET[random_int(0, strlen(self::RECOVERY_CODE_ALPHABET) - 1)];
        }

        return $chunk;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[\s-]+/', '', $code)));
    }

    private function challengeKey(string $token): string
    {
        return self::CHALLENGE_CACHE_PREFIX.hash('sha256', $token);
    }
}
