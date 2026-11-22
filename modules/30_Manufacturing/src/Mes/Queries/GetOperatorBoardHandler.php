<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Queries;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * Answers the operator board query: active phases first (running, setup, suspended, assigned), then the open
 * operations of the work centre nobody took over, oldest scheduled first.
 */
final class GetOperatorBoardHandler
{
    private const int Limit = 100;

    public function __construct(private readonly PhaseViewFactory $views) {}

    /**
     * The board of the work centre as of now.
     *
     * @return array{server_time: string, work_center: array<string, mixed>, phases: list<array<string, mixed>>, available: list<array<string, mixed>>}
     */
    public function handle(GetOperatorBoardQuery $query): array
    {
        $now = CarbonImmutable::now();
        $workCenter = MfgWorkCenter::query()->where('tenant_id', $query->tenantId)->findOrFail($query->workCenterId);

        $phases = MfgMesPhase::query()
            ->where('tenant_id', $query->tenantId)
            ->where('work_center_id', $workCenter->id)
            ->whereIn('status', [PhaseStatus::Assigned->value, PhaseStatus::Setup->value, PhaseStatus::Running->value, PhaseStatus::Suspended->value])
            ->when($query->operatorId !== null, fn ($builder) => $builder->where('operator_id', $query->operatorId))
            ->orderBy('taken_over_at')
            ->limit(self::Limit)
            ->get();

        $taken = MfgMesPhase::query()->where('tenant_id', $query->tenantId)->where('work_center_id', $workCenter->id)->pluck('production_order_operation_id');
        $available = MfgProductionOrderOperation::query()
            ->where('tenant_id', $query->tenantId)
            ->where('work_center_id', $workCenter->id)
            ->where('is_subcontracted', false)
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotIn('id', $taken)
            ->whereIn('production_order_id', MfgProductionOrder::query()->where('tenant_id', $query->tenantId)->whereIn('status', MfgProductionOrder::OpenStatuses)->select('id'))
            ->orderBy('scheduled_date')
            ->orderBy('sequence')
            ->limit(self::Limit)
            ->get();

        return [
            'server_time' => $now->toIso8601String(),
            'work_center' => ['id' => (string) $workCenter->id, 'code' => $workCenter->work_center_code, 'name' => $workCenter->name],
            'phases' => $this->views->forPhases($phases, $now),
            'available' => $this->views->forOperations($available, $now),
        ];
    }
}
