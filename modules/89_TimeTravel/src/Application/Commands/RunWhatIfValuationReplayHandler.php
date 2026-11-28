<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Commands;

use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Models\WhatIfSimulationRun;
use Modules\TimeTravel\Services\EventStreamReader;
use Modules\TimeTravel\Services\InventoryCostReplayer;
use Modules\TimeTravel\Support\Amount;
use Modules\TimeTravel\Support\Instant;

/**
 * Replays the inventory stream up to (valid_at, known_at) under another valuation method and compares it with the official valuation (the
 * books as recorded). Nothing of the ledger or of the inventory tables is read or written: the only write is the run itself.
 */
final readonly class RunWhatIfValuationReplayHandler
{
    public function __construct(private EventStreamReader $reader) {}

    public function handle(RunWhatIfValuationReplayCommand $command): WhatIfSimulationRun
    {
        $replayer = new InventoryCostReplayer($command->method);

        $events = $this->reader->stream($command->tenantId, AggregateType::InventoryWms)
            ->where('valid_at', '<=', Instant::db($command->coordinate->validAt))
            ->where('recorded_at', '<=', Instant::db($command->coordinate->knownAt))
            ->orderBy('valid_at')
            ->orderBy('sequence_number')
            ->cursor();

        foreach ($events as $event) {
            $replayer->feed($event);
        }

        $items = array_values(array_filter(
            $replayer->results(),
            static fn (array $item): bool => ($command->itemId === null || $item['item_id'] === $command->itemId)
                && ($command->warehouseId === null || $item['warehouse_id'] === $command->warehouseId),
        ));

        $official = '0';
        $simulated = '0';
        $quantity = '0';

        foreach ($items as $item) {
            $official = Amount::add($official, $item['official_value']);
            $simulated = Amount::add($simulated, $item['simulated_value']);
            $quantity = Amount::add($quantity, $item['quantity']);
        }

        $delta = Amount::sub($simulated, $official);
        $name = $command->simulationName !== null && $command->simulationName !== ''
            ? $command->simulationName
            : $command->method->value.' '.Instant::iso($command->coordinate->validAt);

        return WhatIfSimulationRun::withoutGlobalScopes()->create([
            'tenant_id' => $command->tenantId,
            'simulation_name' => mb_substr($name, 0, 160),
            'target_valid_at' => Instant::db($command->coordinate->validAt),
            'target_known_at' => Instant::db($command->coordinate->knownAt),
            'valuation_method' => $command->method->value,
            'baseline_summary' => ['method' => 'OFFICIAL', 'total_value' => Amount::money($official), 'total_quantity' => Amount::quantity($quantity)],
            'simulated_summary' => ['method' => $command->method->value, 'total_value' => Amount::money($simulated), 'total_quantity' => Amount::quantity($quantity)],
            'delta_summary' => ['total_value' => Amount::money($delta), 'pct' => Amount::round(Amount::isZero($official) ? 0 : Amount::mul(Amount::div($delta, $official), 100), 2)],
            'items' => $items,
            'created_by' => $command->createdBy,
            'created_at' => Instant::now(),
        ]);
    }
}
