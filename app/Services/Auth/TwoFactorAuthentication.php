<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two-factor authentication of the users with an authenticator app (TOTP) and single-use recovery codes.
 *
 * Setup has two steps, so a secret the user never scanned cannot lock them out: enable stores a pending secret, confirm
 * activates it with a first valid code. At login a user with two factors gets a short-lived challenge token instead of
 * an access token; the token is exchanged for an access token with a code. Recovery codes are kept as SHA-256 hashes:
 * they carry 50 random bits each, so a slow hash adds nothing.
 */
final class TwoFactorAuthentication
{
    public const METHOD_TOTP = 'totp';

    public const METHOD_RECOVERY_CODE = 'recovery_code';

    private const CHALLENGE_CACHE_PREFIX = 'auth:two-factor-challenge:';

    private const RECOVERY_CODE_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(private readonly Totp $totp) {}

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
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
            'two_factor_recovery_codes' => null,
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

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
        ])->save();
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
     * @return self::METHOD_*|null
     */
    public function verifyCode(User $user, ?string $code, ?string $recoveryCode): ?string
    {
        return DB::transaction(function () use ($user, $code, $recoveryCode): ?string {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->isEnabled($locked)) {
                return null;
            }

            if (is_string($code) && $code !== '') {
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
    public function createChallenge(User $user, ?string $tenantId): string
    {
        $token = Str::random(64);

        Cache::put($this->challengeKey($token), [
            'user_id' => (string) $user->getKey(),
            'tenant_id' => $tenantId,
            'failed_attempts' => 0,
        ], now()->addMinutes($this->challengeTtlMinutes()));

        return $token;
    }

    /**
     * @return array{user_id: string, tenant_id: string|null, failed_attempts: int}|null
     */
    public function findChallenge(string $token): ?array
    {
        $challenge = Cache::get($this->challengeKey($token));

        return is_array($challenge) ? $challenge : null;
    }

    /**
     * Counts a wrong code; after the configured number of failures the challenge is gone and the user logs in again.
     */
    public function recordFailedAttempt(string $token): void
    {
        $challenge = $this->findChallenge($token);

        if ($challenge === null) {
            return;
        }

        $challenge['failed_attempts']++;

        if ($challenge['failed_attempts'] >= (int) config('auth.two_factor.max_challenge_attempts')) {
            $this->forgetChallenge($token);

            return;
        }

        Cache::put($this->challengeKey($token), $challenge, now()->addMinutes($this->challengeTtlMinutes()));
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
