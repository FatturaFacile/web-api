<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Whether a password appears in known data breaches, through the Have I Been Pwned range API with k-anonymity: only the
 * first five characters of the SHA-1 of the password leave the server (never the password or its full hash), padded
 * responses (`Add-Padding`) hide how many hashes really match, and the answer for a prefix is cached for an hour.
 *
 * The check fails open: when the service cannot be reached (timeout, error status) the password is accepted and the
 * failure is reported, so an outage of a third party never blocks sign-up or a password reset.
 */
final class BreachedPasswords
{
    public function isBreached(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $range = $this->range($prefix);

        if ($range === null) {
            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', $range) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');

            // Padding entries have a count of zero.
            if (hash_equals($suffix, strtoupper(trim($candidate))) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body of the range response for a prefix, or null when it could not be obtained.
     */
    private function range(string $prefix): ?string
    {
        $key = 'pwned-range:'.$prefix;
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $seconds = max(1, (int) config('auth.registration.password.breached_timeout_seconds', 3));
            $response = Http::timeout($seconds)->connectTimeout($seconds)
                ->withHeaders(['Add-Padding' => 'true', 'User-Agent' => config('app.name').'-password-check'])
                ->get(rtrim((string) config('auth.registration.password.breached_api_url'), '/').'/'.$prefix);

            if (! $response->successful()) {
                throw new RuntimeException('The breached password service answered HTTP '.$response->status().'.');
            }

            $body = $response->body();
            Cache::put($key, $body, now()->addMinutes(max(1, (int) config('auth.registration.password.breached_cache_minutes', 60))));

            return $body;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
