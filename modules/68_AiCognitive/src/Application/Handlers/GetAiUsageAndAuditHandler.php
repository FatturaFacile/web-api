<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\AiCognitive\Application\Queries\GetAiUsageAndAuditQuery;
use Modules\AiCognitive\Infrastructure\Models\AiAuditTrail;
use Modules\AiCognitive\Infrastructure\Models\AiUsageLog;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;

/**
 * Token and cost telemetry by provider and the audit trail of the period, with an optional verification of the hash chain.
 */
final readonly class GetAiUsageAndAuditHandler
{
    public function __construct(private AiTenantRunner $runner, private AiAuditor $auditor) {}

    /**
     * @return array{period: array{from: string, to: string}, usage: array<string, mixed>, audit: LengthAwarePaginator<int, AiAuditTrail>, chain: array{valid: bool, checked: int, broken_at: int|null}|null}
     */
    public function handle(GetAiUsageAndAuditQuery $query): array
    {
        return $this->runner->run($query->tenantId, function () use ($query): array {
            $from = $query->from !== null ? CarbonImmutable::parse($query->from)->startOfDay() : CarbonImmutable::now()->startOfMonth();
            $to = $query->to !== null ? CarbonImmutable::parse($query->to)->endOfDay() : CarbonImmutable::now()->endOfDay();

            $byProvider = AiUsageLog::query()->where('tenant_id', $query->tenantId)->whereBetween('created_at', [$from, $to])
                ->selectRaw('provider, COUNT(*) as calls, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(total_tokens) as total_tokens, SUM(cost_usd) as cost_usd, SUM(CASE WHEN cache_hit THEN 1 ELSE 0 END) as cache_hits, SUM(CASE WHEN status = \'ok\' THEN 0 ELSE 1 END) as failures')
                ->groupBy('provider')->get()
                ->map(static fn (AiUsageLog $row): array => [
                    'provider' => $row->provider,
                    'calls' => (int) $row->getAttribute('calls'),
                    'prompt_tokens' => (int) $row->getAttribute('prompt_tokens'),
                    'completion_tokens' => (int) $row->getAttribute('completion_tokens'),
                    'total_tokens' => (int) $row->getAttribute('total_tokens'),
                    'cost_usd' => round((float) $row->getAttribute('cost_usd'), 4),
                    'cache_hits' => (int) $row->getAttribute('cache_hits'),
                    'failures' => (int) $row->getAttribute('failures'),
                ])->values()->all();

            $audit = AiAuditTrail::query()->where('tenant_id', $query->tenantId)->whereBetween('created_at', [$from, $to])
                ->when($query->eventType !== null, static fn ($builder) => $builder->where('event_type', $query->eventType))
                ->orderByDesc('sequence')->paginate(max(1, min($query->perPage, 200)));

            return [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'usage' => [
                    'by_provider' => $byProvider,
                    'total_tokens' => array_sum(array_column($byProvider, 'total_tokens')),
                    'total_cost_usd' => round(array_sum(array_column($byProvider, 'cost_usd')), 4),
                ],
                'audit' => $audit,
                'chain' => $query->verifyChain ? $this->auditor->verify($query->tenantId) : null,
            ];
        });
    }
}
