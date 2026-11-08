<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Service level of approvals: of the steps opened in a period, how many were decided inside their time limit, how many
 * were late or timed out, and how many are still waiting past their limit. Steps closed because the node was left
 * (`skipped`) or the instance was cancelled do not count, they were never really due. Averages are null when no step was
 * decided, never zero.
 */
final readonly class WorkflowSlaReportService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return array{period: array{from: string, to: string}, totals: array<string, int|string|null>, by_definition: list<array<string, int|string|null>>}
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = DB::getPdo()->quote(now()->toDateTimeString());

        $rows = DB::table('workflow_instance_steps as steps')
            ->join('workflow_instances as instances', 'instances.id', '=', 'steps.workflow_instance_id')
            ->join('workflow_definitions as definitions', 'definitions.id', '=', 'instances.workflow_definition_id')
            ->where('steps.tenant_id', (string) $this->tenantContext->getTenantId())
            ->whereNull('steps.deleted_at')
            ->whereIn('steps.status', ['approved', 'rejected', 'timed_out', 'pending'])
            ->whereBetween('steps.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('definitions.code', 'definitions.name')
            ->orderBy('definitions.code')
            ->selectRaw('definitions.code AS code, definitions.name AS name')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE steps.status IN ('approved', 'rejected') AND (steps.due_at IS NULL OR steps.completed_at <= steps.due_at)) AS on_time")
            ->selectRaw("COUNT(*) FILTER (WHERE steps.status IN ('approved', 'rejected') AND steps.due_at IS NOT NULL AND steps.completed_at > steps.due_at) AS late")
            ->selectRaw("COUNT(*) FILTER (WHERE steps.status = 'timed_out') AS timed_out")
            ->selectRaw("COUNT(*) FILTER (WHERE steps.status = 'pending' AND steps.due_at IS NOT NULL AND steps.due_at < {$now}::timestamptz) AS pending_overdue")
            ->selectRaw("COUNT(*) FILTER (WHERE steps.status = 'pending' AND (steps.due_at IS NULL OR steps.due_at >= {$now}::timestamptz)) AS pending_on_track")
            ->selectRaw('COUNT(*) FILTER (WHERE steps.escalated_at IS NOT NULL) AS escalated')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (steps.completed_at - steps.created_at)) / 3600) FILTER (WHERE steps.status IN ('approved', 'rejected')) AS avg_hours_to_decide")
            ->get();

        $by = $rows->map(fn (object $row): array => $this->shape($row, (string) $row->code, (string) $row->name))->values()->all();
        $sum = static fn (string $key): int => (int) $rows->sum(static fn (object $row): int => (int) $row->{$key});
        $decidedRows = $rows->filter(static fn (object $row): bool => $row->avg_hours_to_decide !== null);
        $weighted = $decidedRows->sum(static fn (object $row): float => (float) $row->avg_hours_to_decide * ((int) $row->on_time + (int) $row->late));
        $decided = $sum('on_time') + $sum('late');

        $total = (object) [
            'total' => $sum('total'), 'on_time' => $sum('on_time'), 'late' => $sum('late'), 'timed_out' => $sum('timed_out'),
            'pending_overdue' => $sum('pending_overdue'), 'pending_on_track' => $sum('pending_on_track'), 'escalated' => $sum('escalated'),
            'avg_hours_to_decide' => $decided === 0 ? null : $weighted / $decided,
        ];

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->shape($total),
            'by_definition' => $by,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function shape(object $row, ?string $code = null, ?string $name = null): array
    {
        $breached = (int) $row->late + (int) $row->timed_out + (int) $row->pending_overdue;
        $base = (int) $row->total;

        return [
            ...($code === null ? [] : ['definition_code' => $code, 'definition_name' => $name]),
            'steps' => $base,
            'decided_on_time' => (int) $row->on_time,
            'decided_late' => (int) $row->late,
            'timed_out' => (int) $row->timed_out,
            'pending_overdue' => (int) $row->pending_overdue,
            'pending_on_track' => (int) $row->pending_on_track,
            'escalated' => (int) $row->escalated,
            'breached' => $breached,
            'breach_rate_percent' => $base === 0 ? null : bcround(bcdiv(bcmul((string) $breached, '100', 4), (string) $base, 4), 2),
            'avg_hours_to_decide' => $row->avg_hours_to_decide === null ? null : bcround(number_format((float) $row->avg_hours_to_decide, 4, '.', ''), 2),
        ];
    }
}
