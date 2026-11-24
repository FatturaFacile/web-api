<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Models\TmsTrip;

/**
 * Carriers. The code is stored in upper case; a carrier with open transport orders or trips cannot be deleted.
 */
final class CarrierService extends TransportationCrudService
{
    protected function modelClass(): string
    {
        return TmsCarrier::class;
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim((string) $data['code']));
        }

        if (isset($data['currency'])) {
            $data['currency'] = Str::upper((string) $data['currency']);
        }

        return $data;
    }

    protected function assertDeletable(Model $record): void
    {
        $orders = TmsTransportOrder::query()->where('carrier_id', $record->getKey())->whereIn('status', [TmsTransportOrder::Draft, TmsTransportOrder::Planned, TmsTransportOrder::InTransit, TmsTransportOrder::Exception])->exists();
        $trips = TmsTrip::query()->where('carrier_id', $record->getKey())->whereIn('status', [TmsTrip::Planned, TmsTrip::Dispatched, TmsTrip::InProgress])->exists();

        if ($orders || $trips) {
            throw ValidationException::withMessages(['id' => [__('transportation_errors.carrier.in_use')]]);
        }
    }
}
