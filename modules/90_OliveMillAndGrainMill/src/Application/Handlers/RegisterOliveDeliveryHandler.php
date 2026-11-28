<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\RegisterOliveDeliveryCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\WeighOliveDeliveryCommand;
use Modules\OliveMillAndGrainMill\Application\Services\MillingScheduler;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * Books a crop delivery (lot code, campaign, farmer, hopper slot) and, when the weighbridge reading comes with it, weighs it. The same handler
 * updates a delivery that was not milled yet.
 */
final class RegisterOliveDeliveryHandler
{
    public function __construct(private readonly MillingScheduler $scheduler, private readonly WeighOliveDeliveryHandler $weighing) {}

    /**
     * @throws OliveMillException farmer_required, farmer_not_found, slot_conflict, delivery_locked, delivery_not_found
     */
    public function handle(RegisterOliveDeliveryCommand $command): OliveDelivery
    {
        return DB::transaction(function () use ($command): OliveDelivery {
            $farmer = $this->farmer($command);
            $scheduledAt = $command->scheduledAt === null ? null : Carbon::parse($command->scheduledAt);
            $delivery = $command->id === null ? new OliveDelivery : $this->editable($command->id);

            if ($scheduledAt !== null) {
                $this->scheduler->assertSlotFree($command->hopperLine, $scheduledAt, $command->estimatedDurationMinutes, $command->id);
            }

            $deliveredAt = Carbon::parse($command->deliveredAt);

            $delivery->fill([
                'delivered_at' => $deliveredAt,
                'processing_mode' => $command->processingMode,
                'commodity' => $command->commodity,
                'grain_type' => $command->commodity === Commodity::Cereali ? $command->grainType : null,
                'farmer_contact_id' => $farmer?->id,
                'cultivar' => $command->cultivar,
                'origin_municipality' => $command->originMunicipality,
                'cadastral_reference' => $command->cadastralReference,
                'sian_origin_code' => $command->sianOriginCode,
                'certification' => $command->certification,
                'scheduled_at' => $scheduledAt,
                'estimated_duration_minutes' => $command->estimatedDurationMinutes,
                'hopper_line' => $command->hopperLine,
                'sms_recipient_phone' => $command->smsRecipientPhone ?? $farmer?->phone,
                'notes' => $command->notes,
            ]);

            if (! $delivery->exists) {
                $year = $this->campaignYear($deliveredAt, $command->commodity);
                $delivery->fill([
                    'campaign_year' => $year,
                    'lot_code' => $this->nextLotCode($year, $command->commodity),
                    'status' => DeliveryStatus::Booked,
                    'created_by' => $command->userId,
                ]);
            }

            $delivery->save();

            if ($command->weighing !== null) {
                $delivery = $this->weighing->handle(WeighOliveDeliveryCommand::fromArray(['delivery_id' => (string) $delivery->id] + $command->weighing, $command->userId));
            }

            return $delivery->refresh();
        });
    }

    /**
     * Olives are crushed from October: the campaign of January and February belongs to the previous year.
     */
    public function campaignYear(Carbon $deliveredAt, Commodity $commodity): int
    {
        return $commodity === Commodity::Olive && $deliveredAt->month <= 3 ? $deliveredAt->year - 1 : $deliveredAt->year;
    }

    private function nextLotCode(int $year, Commodity $commodity): string
    {
        $prefix = $commodity === Commodity::Olive ? 'OL' : 'CE';
        $sequence = OliveDelivery::query()->withTrashed()->where('campaign_year', $year)->where('commodity', $commodity->value)->count() + 1;

        do {
            $code = sprintf('%d-%s-%05d', $year, $prefix, $sequence++);
        } while (OliveDelivery::query()->withTrashed()->where('lot_code', $code)->exists());

        return $code;
    }

    private function farmer(RegisterOliveDeliveryCommand $command): ?Contact
    {
        if ($command->processingMode === ProcessingMode::ContoProprio) {
            return null;
        }

        if ($command->farmerContactId === null) {
            throw OliveMillException::because('farmer_required');
        }

        return Contact::query()->find($command->farmerContactId) ?? throw OliveMillException::because('farmer_not_found', [], 404);
    }

    private function editable(string $id): OliveDelivery
    {
        $delivery = OliveDelivery::query()->whereKey($id)->lockForUpdate()->first() ?? throw OliveMillException::because('delivery_not_found', [], 404);

        if (! in_array($delivery->status, [DeliveryStatus::Booked, DeliveryStatus::Weighed], true)) {
            throw OliveMillException::because('delivery_locked', ['status' => $delivery->status->value]);
        }

        return $delivery;
    }
}
