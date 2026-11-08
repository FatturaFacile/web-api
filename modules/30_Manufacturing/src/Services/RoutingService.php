<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Support\Money;

/**
 * Routings of items and their standard times and costs. Labour is costed at the work centre labour rate on labour
 * minutes, overhead at the work centre overhead rate on machine minutes (setup plus run), subcontracted operations at
 * their agreed unit cost.
 */
final readonly class RoutingService implements RoutingServiceInterface
{
    public function createRouting(CreateRoutingDTO $dto): MfgRouting
    {
        return DB::transaction(function () use ($dto): MfgRouting {
            $item = MfgItem::query()->where('tenant_id', $dto->tenantId)->lockForUpdate()->findOrFail($dto->mfgItemId);
            $number = 'RTG-'.strtoupper($item->item_code);

            if (MfgRouting::query()->where('tenant_id', $dto->tenantId)->where('routing_number', $number)->where('revision', $dto->revision)->exists()) {
                throw new ManufacturingOperationException("Routing [{$number}] already has revision [{$dto->revision}].", 'revision');
            }
            if ($dto->operations === []) {
                throw new ManufacturingOperationException('A routing needs at least one operation.', 'operations');
            }

            if ($dto->isDefault) {
                MfgRouting::query()->where('tenant_id', $dto->tenantId)->where('mfg_item_id', $item->id)->update(['is_default' => false]);
            }

            $routing = MfgRouting::create([
                'tenant_id' => $dto->tenantId,
                'routing_number' => $number,
                'mfg_item_id' => $item->id,
                'name' => $dto->name,
                'revision' => $dto->revision,
                'status' => 'active',
                'is_default' => $dto->isDefault,
                'effective_from' => $dto->effectiveFrom,
                'effective_to' => $dto->effectiveTo,
                'notes' => $dto->notes,
            ]);

            $sequence = 10;
            foreach ($dto->operations as $operation) {
                $workCenter = MfgWorkCenter::query()->where('tenant_id', $dto->tenantId)->findOrFail($operation['work_center_id']);
                $subcontracted = (bool) ($operation['is_subcontracted'] ?? false);

                MfgOperation::create([
                    'tenant_id' => $dto->tenantId,
                    'routing_id' => $routing->id,
                    'sequence' => $operation['sequence'] ?? $sequence,
                    'operation_code' => $operation['operation_code'],
                    'name' => $operation['name'],
                    'description' => $operation['description'] ?? null,
                    'work_center_id' => $workCenter->id,
                    'machine_center_id' => $operation['machine_center_id'] ?? null,
                    'setup_time_minutes' => Money::round($operation['setup_time_minutes'] ?? '0'),
                    'run_time_minutes_per_unit' => Money::round($operation['run_time_minutes_per_unit'] ?? '0'),
                    'labor_time_minutes_per_unit' => Money::round($operation['labor_time_minutes_per_unit'] ?? '0'),
                    'queue_time_minutes' => Money::round($operation['queue_time_minutes'] ?? '0'),
                    'wait_time_minutes' => Money::round($operation['wait_time_minutes'] ?? '0'),
                    'teardown_time_minutes' => Money::round($operation['teardown_time_minutes'] ?? '0'),
                    'standard_labor_rate' => $workCenter->hourly_cost_rate,
                    'standard_machine_rate' => $workCenter->hourly_overhead_rate,
                    'is_subcontracted' => $subcontracted,
                    'subcontractor_contact_id' => $operation['subcontractor_contact_id'] ?? null,
                    'subcontract_unit_cost' => Money::round($operation['subcontract_unit_cost'] ?? '0', 4),
                    'quality_plan_id' => $operation['quality_plan_id'] ?? null,
                ]);
                $sequence += 10;
            }

            if ($dto->isDefault) {
                $item->update(['default_routing_id' => $routing->id]);
            }

            return $routing->load('operations.workCenter');
        });
    }

    public function effectiveRoutingFor(MfgItem $item, ?string $date = null): ?MfgRouting
    {
        $day = CarbonImmutable::parse($date ?? now())->toDateString();

        return MfgRouting::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('mfg_item_id', $item->id)
            ->where('status', 'active')
            ->where(static fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(static fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->first();
    }

    public function standardTimesAndCosts(MfgRouting $routing, string $quantity): array
    {
        $rows = [];
        $totals = ['machine_minutes' => '0', 'labor_minutes' => '0', 'labor_cost' => '0', 'overhead_cost' => '0', 'subcontract_cost' => '0'];
        $operations = MfgOperation::query()->with('workCenter')->where('routing_id', $routing->id)->orderBy('sequence')->get();

        foreach ($operations as $operation) {
            $workCenter = $operation->workCenter;
            $machine = $operation->is_subcontracted ? '0' : $operation->machineMinutesFor($quantity);
            $labor = $operation->is_subcontracted ? '0' : $operation->laborMinutesFor($quantity);
            $laborCost = Money::mul(Money::div($labor, '60'), $workCenter->hourly_cost_rate ?? '0');
            $overheadCost = Money::mul(Money::div($machine, '60'), $workCenter->hourly_overhead_rate ?? '0');
            $subcontractCost = $operation->is_subcontracted ? Money::mul($operation->subcontract_unit_cost, $quantity) : '0';

            $rows[] = [
                'operation_id' => (string) $operation->id,
                'sequence' => $operation->sequence,
                'work_center_id' => (string) $operation->work_center_id,
                'machine_minutes' => Money::round($machine),
                'labor_minutes' => Money::round($labor),
                'labor_cost' => Money::round($laborCost, 4),
                'overhead_cost' => Money::round($overheadCost, 4),
                'subcontract_cost' => Money::round($subcontractCost, 4),
            ];

            $totals['machine_minutes'] = Money::add($totals['machine_minutes'], $machine);
            $totals['labor_minutes'] = Money::add($totals['labor_minutes'], $labor);
            $totals['labor_cost'] = Money::add($totals['labor_cost'], $laborCost);
            $totals['overhead_cost'] = Money::add($totals['overhead_cost'], $overheadCost);
            $totals['subcontract_cost'] = Money::add($totals['subcontract_cost'], $subcontractCost);
        }

        return [
            'operations' => $rows,
            'machine_minutes' => Money::round($totals['machine_minutes']),
            'labor_minutes' => Money::round($totals['labor_minutes']),
            'labor_cost' => Money::round($totals['labor_cost'], 4),
            'overhead_cost' => Money::round($totals['overhead_cost'], 4),
            'subcontract_cost' => Money::round($totals['subcontract_cost'], 4),
        ];
    }
}
