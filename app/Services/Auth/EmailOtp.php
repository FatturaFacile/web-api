<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\TwoFactorEmailCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The email one-time code: six digits, kept in the cache only as an HMAC, valid for a few minutes, spent on first use
 * and dead after a few wrong tries. Codes are kept per purpose (enrolment of the factor, verification at login or for
 * a sensitive change) so a code asked for one purpose never answers another. Sending is rate limited per user.
 */
final class EmailOtp
{
    public const PURPOSE_ENROL = 'enrol';

    public const PURPOSE_VERIFY = 'verify';

    private const CACHE_PREFIX = 'auth:two-factor-email:';

    /**
     * Sends a new code (a pending one is replaced) and returns the seconds it stays valid.
     *
     * @param  self::PURPOSE_*  $purpose
     *
     * @throws EmailOtpThrottledException
     */
    public function send(User $user, string $purpose): int
    {
        $this->assertCanSend($user);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $ttl = $this->ttlMinutes() * 60;

        Cache::put($this->key($user, $purpose), [
            'hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
        ], $ttl);

        Mail::to($user->email)->locale($user->locale)->queue(new TwoFactorEmailCodeMail($user, $code, $this->ttlMinutes()));

        return $ttl;
    }

    /**
     * Checks and spends a code. The cache lock makes two parallel requests unable to both use it.
     *
     * @param  self::PURPOSE_*  $purpose
     */
    public function verify(User $user, string $purpose, string $code): bool
    {
        $key = $this->key($user, $purpose);

        return (bool) Cache::lock($key.':lock', 5)->block(3, function () use ($key, $code): bool {
            $pending = Cache::get($key);

            if (! is_array($pending) || ! is_string($pending['hash'] ?? null)) {
                return false;
            }

            $remaining = (int) ($pending['expires_at'] ?? 0) - now()->getTimestamp();
            $attempts = (int) ($pending['attempts'] ?? 0);

            if ($remaining <= 0 || $attempts >= $this->maxAttempts()) {
                Cache::forget($key);

                return false;
            }

            if (! hash_equals($pending['hash'], $this->hash(trim($code)))) {
                $pending['attempts'] = $attempts + 1;

                if ($pending['attempts'] >= $this->maxAttempts()) {
                    Cache::forget($key);
                } else {
                    Cache::put($key, $pending, $remaining);
                }

                return false;
            }

            Cache::forget($key);

            return true;
        });
    }

    /**
     * Drops every pending code of the user (factor removed).
     */
    public function forget(User $user): void
    {
        foreach ([self::PURPOSE_ENROL, self::PURPOSE_VERIFY] as $purpose) {
            Cache::forget($this->key($user, $purpose));
        }
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('auth.two_factor.email.ttl_minutes'));
    }

    /**
     * Seconds until the next code can be asked for (0 when one can be sent now).
     */
    public function resendAvailableIn(User $user): int
    {
        $minute = RateLimiter::tooManyAttempts($this->minuteKey($user), $this->perMinute()) ? RateLimiter::availableIn($this->minuteKey($user)) : 0;
        $hour = RateLimiter::tooManyAttempts($this->hourKey($user), $this->perHour()) ? RateLimiter::availableIn($this->hourKey($user)) : 0;

        return max($minute, $hour);
    }

    /**
     * @throws EmailOtpThrottledException
     */
    private function assertCanSend(User $user): void
    {
        $wait = $this->resendAvailableIn($user);

        if ($wait > 0) {
            throw new EmailOtpThrottledException($wait);
        }

        RateLimiter::hit($this->minuteKey($user), 60);
        RateLimiter::hit($this->hourKey($user), 3600);
    }

    private function key(User $user, string $purpose): string
    {
        return self::CACHE_PREFIX.$purpose.':'.$user->getKey();
    }

    private function minuteKey(User $user): string
    {
        return self::CACHE_PREFIX.'minute:'.$user->getKey();
    }

    private function hourKey(User $user): string
    {
        return self::CACHE_PREFIX.'hour:'.$user->getKey();
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('auth.two_factor.email.max_attempts'));
    }

    private function perMinute(): int
    {
        return max(1, (int) config('auth.two_factor.email.resend_per_minute'));
    }

    private function perHour(): int
    {
        return max(1, (int) config('auth.two_factor.email.resend_per_hour'));
    }
}
