<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\RecordByproductMovementCommand;
use Modules\OliveMillAndGrainMill\Domain\Enums\ByproductType;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;

/**
 * Records the dispatch or use of a by-product with the documents its destination needs: the transport document and the buyer for the pomace, the
 * cadastral parcel, the area and the cubic metres per hectare for the vegetation water spread on land (checked against the yearly agronomic limit).
 */
final class RecordByproductMovementHandler
{
    /**
     * @throws OliveMillException counterparty_required, transport_document_required, spreading_parcel_required, spreading_limit_exceeded
     */
    public function handle(RecordByproductMovementCommand $command): ByproductMovement
    {
        if ($command->quantity <= 0.0) {
            throw OliveMillException::because('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($command): ByproductMovement {
            $date = Carbon::parse($command->movementDate);
            $contact = $command->counterpartyContactId === null ? null : (Contact::query()->find($command->counterpartyContactId) ?? throw OliveMillException::because('counterparty_not_found', [], 404));
            $name = $command->counterpartyName ?? ($contact === null ? null : (trim((string) $contact->company_name) ?: trim($contact->first_name.' '.$contact->last_name)));
            $perHectare = null;

            if ($command->byproductType === ByproductType::SansaVergine) {
                if ($name === null || $name === '') {
                    throw OliveMillException::because('counterparty_required');
                }

                if ($command->transportDocument === null || trim($command->transportDocument) === '') {
                    throw OliveMillException::because('transport_document_required');
                }
            }

            if ($command->byproductType === ByproductType::AcqueVegetazione) {
                $perHectare = $this->assertSpreading($command, $date);
            }

            return ByproductMovement::query()->create([
                'byproduct_type' => $command->byproductType,
                'movement_date' => $date->toDateString(),
                'quantity' => $command->quantity,
                'unit' => $command->byproductType === ByproductType::AcqueVegetazione ? 'm3' : 'kg',
                'delivery_id' => $command->deliveryId,
                'cycle_id' => $command->cycleId,
                'counterparty_contact_id' => $contact?->id,
                'counterparty_name' => $name,
                'destination_type' => $command->destinationType ?? $this->defaultDestination($command->byproductType),
                'transport_document' => $command->transportDocument,
                'cadastral_parcel' => $command->cadastralParcel,
                'parcel_area_ha' => $command->parcelAreaHa,
                'm3_per_ha' => $perHectare,
                'notes' => $command->notes,
                'created_by' => $command->userId,
            ])->refresh();
        });
    }

    /**
     * The spreading needs the parcel and its area; the cubic metres per hectare of this spreading and of the whole year stay within the limit.
     *
     * @throws OliveMillException spreading_parcel_required, spreading_limit_exceeded
     */
    private function assertSpreading(RecordByproductMovementCommand $command, Carbon $date): float
    {
        if ($command->cadastralParcel === null || trim($command->cadastralParcel) === '' || $command->parcelAreaHa === null || $command->parcelAreaHa <= 0.0) {
            throw OliveMillException::because('spreading_parcel_required');
        }

        $limit = (float) config('olive_mill.vegetation_water_max_m3_per_ha', 50.0);
        $already = (float) ByproductMovement::query()
            ->where('byproduct_type', ByproductType::AcqueVegetazione->value)
            ->where('cadastral_parcel', $command->cadastralParcel)
            ->whereYear('movement_date', $date->year)
            ->sum('quantity');
        $perHectare = round(($already + $command->quantity) / $command->parcelAreaHa, 3);

        if ($perHectare > $limit + 0.0005) {
            throw OliveMillException::because('spreading_limit_exceeded', ['per_hectare' => $perHectare, 'limit' => $limit]);
        }

        return round($command->quantity / $command->parcelAreaHa, 3);
    }

    private function defaultDestination(ByproductType $type): string
    {
        return match ($type) {
            ByproductType::SansaVergine => 'SANSIFICIO',
            ByproductType::Nocciolino => 'BIOMASS',
            ByproductType::AcqueVegetazione => 'LAND_SPREADING',
            ByproductType::Crusca => 'FEED',
        };
    }
}
