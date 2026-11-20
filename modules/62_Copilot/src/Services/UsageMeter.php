<?php

declare(strict_types=1);

namespace Modules\Copilot\Services;

use Illuminate\Support\Facades\RateLimiter;
use Modules\Copilot\DTOs\TokenUsage;
use Modules\Copilot\Models\CopilotUsage;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Per-tenant token quota (tokens of the current day) and per-user request rate of the Copilot.
 */
final class UsageMeter
{
    /**
     * @throws TooManyRequestsHttpException when the user sends too fast or the tenant spent its daily tokens
     */
    public function assertAllowed(string $tenantId, string $userId): void
    {
        $key = "copilot:{$tenantId}:{$userId}";
        $limit = (int) config('copilot.quota.user_requests_per_minute', 20);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key), 'Too many Copilot requests, retry shortly.');
        }

        RateLimiter::hit($key, 60);

        $quota = (int) config('copilot.quota.tenant_daily_tokens', 500000);

        if ($quota > 0 && $this->tokensToday($tenantId) >= $quota) {
            throw new TooManyRequestsHttpException(3600, 'The daily Copilot token quota of the company is exhausted.');
        }
    }

    public function tokensToday(string $tenantId): int
    {
        return (int) CopilotUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('total_tokens');
    }

    public function record(TokenUsage $usage, string $tenantId, string $userId, ?string $conversationId): void
    {
        if ($usage->totalTokens === 0) {
            return;
        }

        CopilotUsage::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'provider' => $usage->provider,
            'model' => $usage->model,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->totalTokens,
        ]);
    }
}
