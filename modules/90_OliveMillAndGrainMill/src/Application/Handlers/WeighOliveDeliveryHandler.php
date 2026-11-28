<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\WeighOliveDeliveryCommand;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\WeighbridgeMeasurement;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * Records the weighbridge reading of a delivery: net weight = gross - vehicle tare - crates x unit tare. The reading may come from the operator
 * or from a scale integration (payload and timestamp are kept).
 */
final class WeighOliveDeliveryHandler
{
    /**
     * @throws OliveMillException delivery_not_found, delivery_not_weighable, weight_invalid, net_weight_not_positive
     */
    public function handle(WeighOliveDeliveryCommand $command): OliveDelivery
    {
        return DB::transaction(function () use ($command): OliveDelivery {
            $delivery = OliveDelivery::query()->whereKey($command->deliveryId)->lockForUpdate()->first() ?? throw OliveMillException::because('delivery_not_found', [], 404);

            if (! in_array($delivery->status, [DeliveryStatus::Booked, DeliveryStatus::Weighed], true)) {
                throw OliveMillException::because('delivery_not_weighable', ['status' => $delivery->status->value]);
            }

            $measurement = new WeighbridgeMeasurement($command->grossWeightKg, $command->tareKg, $command->crateCount, $command->crateUnitTareKg);

            $delivery->forceFill([
                'gross_weight_kg' => $command->grossWeightKg,
                'tare_kg' => $command->tareKg,
                'crate_count' => $command->crateCount,
                'crate_unit_tare_kg' => $command->crateUnitTareKg,
                'net_weight_kg' => $measurement->netKg(),
                'weighbridge_ticket' => $command->weighbridgeTicket,
                'scale_payload' => $command->scalePayload,
                'scale_read_at' => $command->scaleReadAt === null ? null : Carbon::parse($command->scaleReadAt),
                'status' => DeliveryStatus::Weighed,
            ])->save();

            return $delivery->refresh();
        });
    }
}
