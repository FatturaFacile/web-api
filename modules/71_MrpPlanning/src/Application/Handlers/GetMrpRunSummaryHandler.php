<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Modules\MrpPlanning\Application\Queries\GetMrpRunSummaryQuery;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterCapacityBucket;

/**
 * A run with the live KPIs of its proposals and capacity buckets (they change as the planner firms, releases and levels).
 */
final class GetMrpRunSummaryHandler
{
    /**
     * @return array{run: MrpRunExecution, kpis: array<string, mixed>}
     */
    public function handle(GetMrpRunSummaryQuery $query): array
    {
        $run = MrpRunExecution::query()->where('tenant_id', $query->tenantId)->whereKey($query->runId)->first();
        if (! $run instanceof MrpRunExecution) {
            throw new MrpNotFoundException('MRP run not found.');
        }

        $byType = [];
        $byStatus = [];
        $byException = [];
        foreach (MrpActionProposal::query()->where('tenant_id', $query->tenantId)->where('mrp_run_id', $run->id)->get(['proposal_type', 'status', 'exception_messages']) as $proposal) {
            $byType[$proposal->proposal_type->value] = ($byType[$proposal->proposal_type->value] ?? 0) + 1;
            $byStatus[$proposal->status->value] = ($byStatus[$proposal->status->value] ?? 0) + 1;
            foreach ($proposal->exception_messages ?? [] as $code) {
                $byException[$code] = ($byException[$code] ?? 0) + 1;
            }
        }

        $buckets = MrpWorkCenterCapacityBucket::query()->where('tenant_id', $query->tenantId)->where('mrp_run_id', $run->id)->get(['is_overloaded', 'load_percentage', 'work_center_id']);

        return [
            'run' => $run,
            'kpis' => [
                'proposals_total' => array_sum($byType),
                'by_type' => $byType,
                'by_status' => $byStatus,
                'by_exception' => $byException,
                'capacity_buckets' => $buckets->count(),
                'overloaded_buckets' => $buckets->where('is_overloaded', true)->count(),
                'overloaded_work_centers' => $buckets->where('is_overloaded', true)->pluck('work_center_id')->unique()->count(),
                'max_load_percentage' => $buckets->isEmpty() ? '0.00' : (string) $buckets->max(static fn (MrpWorkCenterCapacityBucket $bucket): string => (string) $bucket->load_percentage),
                'execution_duration_ms' => $run->execution_duration_ms,
            ],
        ];
    }
}
