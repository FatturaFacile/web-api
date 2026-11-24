<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Events\ProductionRoutingDefinedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Services\ProductionEngineering\RoutingOperationWriter;
use Modules\Manufacturing\Support\Money;

/**
 * Routings of items and their standard times and costs. Labour is costed at the work centre labour rate on labour
 * minutes, overhead at the work centre overhead rate on machine minutes (setup plus run), subcontracted operations at
 * their agreed unit cost.
 */
final readonly class RoutingService implements RoutingServiceInterface
{
    public function __construct(private RoutingOperationWriter $writer, private EventDispatcher $dispatcher) {}

    /**
     * {@inheritDoc}
     */
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
                'bom_id' => $dto->bomId,
            ]);

            $this->writer->write($dto->tenantId, $routing, $dto->operations);
            $this->dispatcher->publishAndDispatch(new ProductionRoutingDefinedEvent((string) $routing->id, $routing->routing_number, $dto->tenantId));

            if ($dto->isDefault) {
                $item->update(['default_routing_id' => $routing->id]);
            }

            return $routing->load(['operations.workCenter', 'operations.bomComponents']);
        });
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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
