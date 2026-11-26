<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Services\Tenancy\TenantContext;
use App\Support\Database\SqlDialect;
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
            ->selectRaw("COUNT(CASE WHEN steps.status IN ('approved', 'rejected') AND (steps.due_at IS NULL OR steps.completed_at <= steps.due_at) THEN 1 END) AS on_time")
            ->selectRaw("COUNT(CASE WHEN steps.status IN ('approved', 'rejected') AND steps.due_at IS NOT NULL AND steps.completed_at > steps.due_at THEN 1 END) AS late")
            ->selectRaw("COUNT(CASE WHEN steps.status = 'timed_out' THEN 1 END) AS timed_out")
            ->selectRaw("COUNT(CASE WHEN steps.status = 'pending' AND steps.due_at IS NOT NULL AND steps.due_at < {$now} THEN 1 END) AS pending_overdue")
            ->selectRaw("COUNT(CASE WHEN steps.status = 'pending' AND (steps.due_at IS NULL OR steps.due_at >= {$now}) THEN 1 END) AS pending_on_track")
            ->selectRaw('COUNT(CASE WHEN steps.escalated_at IS NOT NULL THEN 1 END) AS escalated')
            ->selectRaw("AVG(CASE WHEN steps.status IN ('approved', 'rejected') THEN ".SqlDialect::secondsBetween('steps.created_at', 'steps.completed_at').' / 3600.0 END) AS avg_hours_to_decide')
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

        $byNode = $this->breakdown($from, $to, 'nodes.name', 'nodes.id', 'node');
        $byApprover = $this->breakdown($from, $to, 'users.name', 'steps.assigned_user_id', 'approver', true);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->shape($total),
            'by_definition' => $by,
            'by_node' => $byNode,
            'by_approver' => $byApprover,
            // The slowest decided nodes first: where approvals wait the longest.
            'bottlenecks' => collect($byNode)->filter(static fn (array $row): bool => $row['avg_hours_to_decide'] !== null)
                ->sortByDesc(static fn (array $row): float => (float) $row['avg_hours_to_decide'])->take(5)->values()->all(),
        ];
    }

    /**
     * Average decision time, overdue and open items per node or per approver.
     *
     * @return list<array<string, mixed>>
     */
    private function breakdown(CarbonImmutable $from, CarbonImmutable $to, string $labelColumn, string $groupColumn, string $kind, bool $approver = false): array
    {
        $now = DB::getPDO()->quote(now()->toDateTimeString());

        $query = DB::table('workflow_instance_steps as steps')
            ->join('workflow_nodes as nodes', 'nodes.id', '=', 'steps.workflow_node_id')
            ->where('steps.tenant_id', (string) $this->tenantContext->getTenantId())
            ->whereNull('steps.deleted_at')
            ->whereBetween('steps.created_at', [$from->startOfDay(), $to->endOfDay()]);

        if ($approver) {
            $query->join('users', 'users.id', '=', 'steps.assigned_user_id')->whereNotNull('steps.assigned_user_id');
        }

        return $query
            ->groupBy($groupColumn, $labelColumn)
            ->orderBy($labelColumn)
            ->selectRaw("{$groupColumn} AS ref, {$labelColumn} AS label")
            ->selectRaw('COUNT(*) AS steps')
            ->selectRaw("COUNT(CASE WHEN steps.status = 'pending' AND steps.due_at IS NOT NULL AND steps.due_at < {$now} THEN 1 END) AS overdue")
            ->selectRaw("COUNT(CASE WHEN steps.status = 'pending' THEN 1 END) AS open")
            ->selectRaw("AVG(CASE WHEN steps.status IN ('approved', 'rejected') THEN ".SqlDialect::secondsBetween('steps.created_at', 'steps.completed_at').' / 3600.0 END) AS avg_hours')
            ->get()
            ->map(static fn (object $row): array => [
                $kind.'_id' => (string) $row->ref,
                $kind.'_name' => (string) $row->label,
                'steps' => (int) $row->steps,
                'overdue' => (int) $row->overdue,
                'open' => (int) $row->open,
                'avg_hours_to_decide' => $row->avg_hours === null ? null : bcround(number_format((float) $row->avg_hours, 4, '.', ''), 2),
            ])
            ->values()
            ->all();
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
