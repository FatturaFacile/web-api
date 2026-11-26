<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Sms\SmsDeliveryException;
use App\Contracts\Sms\SmsGateway;
use App\Mail\TwoFactorEmailCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one-time codes sent to the user by email or SMS: six digits, kept in the cache only as an HMAC, valid for a few
 * minutes, spent on first use and dead after a few wrong tries. Codes are kept per channel and purpose (enrolment of
 * the factor, verification at login or for a sensitive change) so a code asked for one never answers another. Sending
 * is rate limited per user and channel: one code a minute and a few an hour (`auth.two_factor.{channel}`).
 */
final class SecondFactorCodes
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const PURPOSE_ENROL = 'enrol';

    public const PURPOSE_VERIFY = 'verify';

    private const CACHE_PREFIX = 'auth:two-factor-code:';

    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * Sends a new code (a pending one is replaced) and returns the seconds it stays valid. By SMS the code goes to
     * `$phone` (the number being enrolled) or, without it, to the confirmed number of the user.
     *
     * @param  self::PURPOSE_*  $purpose
     * @param  self::CHANNEL_*  $channel
     *
     * @throws OtpThrottledException
     * @throws SmsDeliveryException
     */
    public function send(User $user, string $purpose, string $channel = self::CHANNEL_EMAIL, ?string $phone = null): int
    {
        $number = null;

        if ($channel === self::CHANNEL_SMS) {
            $number = $phone ?? $user->two_factor_sms_phone;

            if (! is_string($number) || $number === '') {
                throw new SmsDeliveryException('The user has no phone number.');
            }
        }

        $this->assertCanSend($user, $channel);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $ttl = $this->ttlMinutes($channel) * 60;

        Cache::put($this->key($user, $purpose, $channel), [
            'hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
            'phone' => $number,
        ], $ttl);

        if ($number === null) {
            Mail::to($user->email)->locale($user->locale)->queue(new TwoFactorEmailCodeMail($user, $code, $this->ttlMinutes($channel)));
        } else {
            $this->sms->send($number, (string) __('two_factor.sms.code', [
                'app' => config('app.name'),
                'code' => $code,
                'minutes' => $this->ttlMinutes($channel),
            ], (string) ($user->locale ?? app()->getLocale())));
        }

        return $ttl;
    }

    /**
     * Checks and spends a code. The cache lock makes two parallel requests unable to both use it.
     *
     * @param  self::PURPOSE_*  $purpose
     * @param  self::CHANNEL_*  $channel
     */
    public function verify(User $user, string $purpose, string $code, string $channel = self::CHANNEL_EMAIL): bool
    {
        $key = $this->key($user, $purpose, $channel);
        $max = $this->maxAttempts($channel);

        return (bool) Cache::lock($key.':lock', 5)->block(3, function () use ($key, $code, $max): bool {
            $pending = Cache::get($key);

            if (! is_array($pending) || ! is_string($pending['hash'] ?? null)) {
                return false;
            }

            $remaining = (int) ($pending['expires_at'] ?? 0) - now()->getTimestamp();
            $attempts = (int) ($pending['attempts'] ?? 0);

            if ($remaining <= 0 || $attempts >= $max) {
                Cache::forget($key);

                return false;
            }

            if (! hash_equals($pending['hash'], $this->hash(trim($code)))) {
                $pending['attempts'] = $attempts + 1;

                if ($pending['attempts'] >= $max) {
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
     * The number an enrolment code was sent to, before it is confirmed.
     */
    public function pendingPhone(User $user): ?string
    {
        $pending = Cache::get($this->key($user, self::PURPOSE_ENROL, self::CHANNEL_SMS));
        $phone = is_array($pending) ? ($pending['phone'] ?? null) : null;

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * Drops the pending codes of a channel (factor removed), or of every channel.
     *
     * @param  self::CHANNEL_*|null  $channel
     */
    public function forget(User $user, ?string $channel = null): void
    {
        foreach ($channel === null ? [self::CHANNEL_EMAIL, self::CHANNEL_SMS] : [$channel] as $each) {
            foreach ([self::PURPOSE_ENROL, self::PURPOSE_VERIFY] as $purpose) {
                Cache::forget($this->key($user, $purpose, $each));
            }
        }
    }

    /**
     * @param  self::CHANNEL_*  $channel
     */
    public function ttlMinutes(string $channel = self::CHANNEL_EMAIL): int
    {
        return max(1, (int) config("auth.two_factor.{$channel}.ttl_minutes"));
    }

    /**
     * Seconds until the next code can be asked for (0 when one can be sent now).
     *
     * @param  self::CHANNEL_*  $channel
     */
    public function resendAvailableIn(User $user, string $channel = self::CHANNEL_EMAIL): int
    {
        $minute = RateLimiter::tooManyAttempts($this->minuteKey($user, $channel), $this->perMinute($channel)) ? RateLimiter::availableIn($this->minuteKey($user, $channel)) : 0;
        $hour = RateLimiter::tooManyAttempts($this->hourKey($user, $channel), $this->perHour($channel)) ? RateLimiter::availableIn($this->hourKey($user, $channel)) : 0;

        return max($minute, $hour);
    }

    /**
     * @throws OtpThrottledException
     */
    private function assertCanSend(User $user, string $channel): void
    {
        $wait = $this->resendAvailableIn($user, $channel);

        if ($wait > 0) {
            throw new OtpThrottledException($wait);
        }

        RateLimiter::hit($this->minuteKey($user, $channel), 60);
        RateLimiter::hit($this->hourKey($user, $channel), 3600);
    }

    private function key(User $user, string $purpose, string $channel): string
    {
        return self::CACHE_PREFIX.$channel.':'.$purpose.':'.$user->getKey();
    }

    private function minuteKey(User $user, string $channel): string
    {
        return self::CACHE_PREFIX.$channel.':minute:'.$user->getKey();
    }

    private function hourKey(User $user, string $channel): string
    {
        return self::CACHE_PREFIX.$channel.':hour:'.$user->getKey();
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function maxAttempts(string $channel): int
    {
        return max(1, (int) config("auth.two_factor.{$channel}.max_attempts"));
    }

    private function perMinute(string $channel): int
    {
        return max(1, (int) config("auth.two_factor.{$channel}.resend_per_minute"));
    }

    private function perHour(string $channel): int
    {
        return max(1, (int) config("auth.two_factor.{$channel}.resend_per_hour"));
    }
}
