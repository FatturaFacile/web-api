<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Queries;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMesMaterialConsumption;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgMesProductionDeclaration;
use Modules\Manufacturing\Models\MfgMesScrapDeclaration;
use Modules\Manufacturing\Models\MfgMesTimeLog;

/**
 * Answers the traceability query of a phase.
 */
final class GetPhaseTraceabilityHandler
{
    public function __construct(private readonly PhaseViewFactory $views) {}

    /**
     * The phase with its intervals, declarations, scrap reasons and consumed lots.
     *
     * @return array<string, mixed>
     */
    public function handle(GetPhaseTraceabilityQuery $query): array
    {
        $phase = MfgMesPhase::query()->where('tenant_id', $query->tenantId)->findOrFail($query->phaseId);
        $now = CarbonImmutable::now();
        $consumptions = MfgMesMaterialConsumption::query()->where('phase_id', $phase->id)->orderBy('consumed_at')->get();
        $items = MfgItem::query()->whereIn('id', $consumptions->pluck('mfg_item_id'))->get()->keyBy('id');

        return [
            'phase' => $this->views->forPhases(collect([$phase]), $now)[0],
            'intervals' => MfgMesTimeLog::query()->where('phase_id', $phase->id)->orderBy('started_at')->get()->map(static fn (MfgMesTimeLog $log): array => [
                'category' => $log->category->value,
                'operator_id' => $log->operator_id,
                'machine_attended' => $log->machine_attended,
                'suspension_reason' => $log->suspension_reason?->value,
                'started_at' => $log->started_at->toIso8601String(),
                'ended_at' => $log->ended_at?->toIso8601String(),
            ])->all(),
            'declarations' => MfgMesProductionDeclaration::query()->where('phase_id', $phase->id)->orderBy('declared_at')->get()->map(static fn (MfgMesProductionDeclaration $row): array => [
                'id' => (string) $row->id,
                'operator_id' => $row->operator_id,
                'good_quantity' => $row->good_quantity,
                'scrap_quantity' => $row->scrap_quantity,
                'declared_at' => $row->declared_at->toIso8601String(),
            ])->all(),
            'scraps' => MfgMesScrapDeclaration::query()->where('phase_id', $phase->id)->get()->map(static fn (MfgMesScrapDeclaration $row): array => [
                'production_declaration_id' => (string) $row->production_declaration_id,
                'quantity' => $row->quantity,
                'defect_reason' => $row->defect_reason->value,
                'note' => $row->note,
            ])->all(),
            'materials' => $consumptions->map(static fn (MfgMesMaterialConsumption $row): array => [
                'id' => (string) $row->id,
                'mfg_item_id' => (string) $row->mfg_item_id,
                'item_code' => $items->get($row->mfg_item_id)?->item_code,
                'lot_code' => $row->lot_code,
                'quantity' => $row->quantity,
                'operator_id' => $row->operator_id,
                'consumed_at' => $row->consumed_at->toIso8601String(),
            ])->all(),
        ];
    }
}
