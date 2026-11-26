<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Temporary rate limiting of an IP address after repeated failed logins (Mod 004). The failures are counted in a sliding
 * window; when the limit is reached the address is refused for a while. The ledger records the block as a security entry.
 */
final class LoginThrottle
{
    public function enabled(): bool
    {
        return (bool) config('audit_ledger.login_throttle.enabled', true);
    }

    /**
     * Counts a failed login of the address and returns true when this failure blocked it.
     */
    public function hit(?string $ip): bool
    {
        if (! $this->enabled() || $ip === null || $ip === '') {
            return false;
        }

        $window = max(1, (int) config('audit_ledger.login_throttle.window_minutes', 15)) * 60;
        $max = max(1, (int) config('audit_ledger.login_throttle.max_failures', 10));
        $key = $this->failuresKey($ip);

        $this->store()->add($key, 0, $window);
        $failures = (int) $this->store()->increment($key);

        if ($failures < $max || $this->isBlocked($ip)) {
            return false;
        }

        $this->store()->put($this->blockKey($ip), true, max(1, (int) config('audit_ledger.login_throttle.block_minutes', 15)) * 60);
        $this->store()->forget($key);

        return true;
    }

    public function isBlocked(?string $ip): bool
    {
        return $this->enabled() && $ip !== null && $ip !== '' && $this->store()->has($this->blockKey($ip));
    }

    public function retryAfterSeconds(): int
    {
        return max(1, (int) config('audit_ledger.login_throttle.block_minutes', 15)) * 60;
    }

    private function store(): Repository
    {
        return Cache::store();
    }

    private function failuresKey(string $ip): string
    {
        return 'audit-ledger:login-failures:'.hash('sha256', $ip);
    }

    private function blockKey(string $ip): string
    {
        return 'audit-ledger:login-blocked:'.hash('sha256', $ip);
    }
}
