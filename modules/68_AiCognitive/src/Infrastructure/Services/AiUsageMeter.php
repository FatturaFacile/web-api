<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\RateLimiter;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Exceptions\AiQuotaExceededException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\AiCognitive\Infrastructure\Models\AiUsageLog;

/**
 * Token quotas, rate limits and the usage and cost log (`ai_usage_logs`). The monthly token budget and the rate limit are
 * set per provider of the tenant; without a value the config default of the rate limit applies and the budget is open.
 */
final class AiUsageMeter
{
    /**
     * @throws AiQuotaExceededException
     */
    public function assertAllowed(ResolvedProvider $provider, string $tenantId): void
    {
        $budget = $provider->config?->monthly_token_budget;

        if ($budget !== null && $budget > 0 && $this->monthTokens($tenantId, $provider->type->value) >= $budget) {
            throw AiQuotaExceededException::monthlyBudget($provider->type->value, $budget);
        }

        $key = "ai-cognitive:rate:{$tenantId}:{$provider->type->value}";
        $limit = $provider->config->rate_limit_per_minute ?? (int) config('ai_cognitive.resilience.rate_limit_per_minute', 60);

        if ($limit > 0 && RateLimiter::tooManyAttempts($key, $limit)) {
            throw AiQuotaExceededException::rateLimited();
        }

        RateLimiter::hit($key, 60);
    }

    public function monthTokens(string $tenantId, ?string $provider = null): int
    {
        return (int) AiUsageLog::query()
            ->where('tenant_id', $tenantId)
            ->when($provider !== null, static fn ($query) => $query->where('provider', $provider))
            ->where('status', 'ok')
            ->where('cache_hit', false)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('total_tokens');
    }

    public function cost(string $provider, int $promptTokens, int $completionTokens): float
    {
        /** @var array{0: float, 1: float} $price */
        $price = (array) config("ai_cognitive.pricing.{$provider}", [0.0, 0.0]);

        return round($promptTokens / 1000 * (float) $price[0] + $completionTokens / 1000 * (float) $price[1], 6);
    }

    public function record(string $tenantId, ?string $userId, string $provider, ?string $model, AiTaskType $task, DataClassification $classification, ?AiCompletionResult $result, string $status, ?string $error = null, bool $piiMasked = false): AiUsageLog
    {
        return AiUsageLog::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'provider' => $provider,
            'model' => $result->model ?? $model,
            'task_type' => $task->value,
            'data_classification' => $classification->value,
            'prompt_tokens' => $result->promptTokens ?? 0,
            'completion_tokens' => $result->completionTokens ?? 0,
            'total_tokens' => $result->totalTokens ?? 0,
            'cost_usd' => $result->costUsd ?? 0,
            'latency_ms' => $result->latencyMs ?? 0,
            'cache_hit' => $result->cacheHit ?? false,
            'pii_masked' => $piiMasked,
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
        ]);
    }
}
