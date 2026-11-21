<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per tenant and provider circuit breaker: after N consecutive failures the provider is skipped for a while, so a dead
 * endpoint does not make every request wait for its timeout. The first success closes the circuit again.
 */
final class CircuitBreaker
{
    public function isOpen(string $tenantId, string $provider): bool
    {
        return Cache::has($this->key($tenantId, $provider, 'open'));
    }

    public function recordFailure(string $tenantId, string $provider): void
    {
        $failures = (int) Cache::get($this->key($tenantId, $provider, 'failures'), 0) + 1;
        Cache::put($this->key($tenantId, $provider, 'failures'), $failures, now()->addMinutes(10));

        if ($failures >= (int) config('ai_cognitive.resilience.circuit_failure_threshold', 5)) {
            Cache::put($this->key($tenantId, $provider, 'open'), true, now()->addSeconds((int) config('ai_cognitive.resilience.circuit_open_seconds', 60)));
            Cache::forget($this->key($tenantId, $provider, 'failures'));
        }
    }

    public function recordSuccess(string $tenantId, string $provider): void
    {
        Cache::forget($this->key($tenantId, $provider, 'failures'));
    }

    private function key(string $tenantId, string $provider, string $part): string
    {
        return "ai-cognitive:circuit:{$tenantId}:{$provider}:{$part}";
    }
}
