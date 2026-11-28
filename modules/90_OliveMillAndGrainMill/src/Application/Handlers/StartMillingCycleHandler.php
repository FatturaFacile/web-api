<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\StartMillingCycleCommand;
use Modules\OliveMillAndGrainMill\Domain\Enums\CycleStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * Puts a weighed lot into the hopper: the milling cycle opens and the delivery shows as in the hopper.
 */
final class StartMillingCycleHandler
{
    /**
     * @throws OliveMillException delivery_not_found, delivery_not_weighed, cycle_exists
     */
    public function handle(StartMillingCycleCommand $command): MillingCycle
    {
        return DB::transaction(function () use ($command): MillingCycle {
            $delivery = OliveDelivery::query()->whereKey($command->deliveryId)->lockForUpdate()->first() ?? throw OliveMillException::because('delivery_not_found', [], 404);

            if ($delivery->status !== DeliveryStatus::Weighed || $delivery->net_weight_kg === null) {
                throw OliveMillException::because('delivery_not_weighed', ['status' => $delivery->status->value]);
            }

            if (MillingCycle::query()->withTrashed()->where('delivery_id', $delivery->id)->exists()) {
                throw OliveMillException::because('cycle_exists');
            }

            $cycle = MillingCycle::query()->create([
                'delivery_id' => $delivery->id,
                'line_code' => $command->lineCode ?? $delivery->hopper_line,
                'status' => CycleStatus::InProgress,
                'started_at' => $command->startedAt === null ? now() : Carbon::parse($command->startedAt),
                'malaxation_temperature_c' => $command->malaxationTemperatureC,
                'water_added_l' => $command->waterAddedL ?? 0,
                'input_net_kg' => $delivery->net_weight_kg,
                'density_kg_per_l' => OilDensityConverter::fromConfig()->density,
                'created_by' => $command->userId,
            ]);

            $delivery->forceFill(['status' => DeliveryStatus::InHopper])->save();

            return $cycle->refresh();
        });
    }
}
