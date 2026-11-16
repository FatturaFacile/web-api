<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsQuote;
use Modules\Transportation\Models\TmsTransportOrder;

/**
 * Freight quotes: a comparison of the carriers for a shipment, saved with its offers ranked cheapest first, and the award
 * of one offer, which also gives the carrier and the expected cost to the transport order of the quote.
 */
final class QuoteService extends TransportationCrudService
{
    public function __construct(private readonly RateCalculator $rates, private readonly TransportOrderService $orders) {}

    protected function modelClass(): string
    {
        return TmsQuote::class;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function compare(string $tenantId, array $data): TmsQuote
    {
        return DB::transaction(function () use ($tenantId, $data): TmsQuote {
            $order = isset($data['transport_order_id']) ? TmsTransportOrder::query()->where('tenant_id', $tenantId)->findOrFail($data['transport_order_id']) : null;

            $weight = (string) ($data['weight_kg'] ?? $order?->weight_kg);
            $distance = $data['distance_km'] ?? $order?->distance_km;
            $originZone = $data['origin_zone'] ?? $order?->origin_zone;
            $destinationZone = $data['destination_zone'] ?? $order?->destination_zone;
            $shipDate = (string) ($data['ship_date'] ?? $order?->pickup_from?->toDateString() ?? now()->toDateString());
            $currency = Str::upper((string) ($data['currency'] ?? $order?->currency ?? 'EUR'));

            $offers = $this->rates->compare($tenantId, $weight, $distance !== null ? (string) $distance : null, $originZone, $destinationZone, $shipDate, $currency);

            if ($offers === []) {
                throw ValidationException::withMessages(['weight_kg' => [__('transportation_errors.quote.no_offers')]]);
            }

            return TmsQuote::create([
                'tenant_id' => $tenantId,
                'reference' => $data['reference'] ?? null,
                'transport_order_id' => $order?->id,
                'ship_date' => $shipDate,
                'weight_kg' => $weight,
                'distance_km' => $distance,
                'origin_zone' => $originZone,
                'destination_zone' => $destinationZone,
                'currency' => $currency,
                'offers' => $offers,
                'notes' => $data['notes'] ?? null,
            ])->refresh();
        });
    }

    public function award(TmsQuote $quote, string $carrierId): TmsQuote
    {
        return DB::transaction(function () use ($quote, $carrierId): TmsQuote {
            $locked = TmsQuote::query()->lockForUpdate()->findOrFail($quote->id);

            if ($locked->status !== TmsQuote::Open) {
                throw ValidationException::withMessages(['status' => [__('transportation_errors.quote.not_open', ['status' => $locked->status])]]);
            }

            $offer = collect($locked->offers ?? [])->firstWhere('carrier_id', $carrierId);

            if ($offer === null) {
                throw ValidationException::withMessages(['carrier_id' => [__('transportation_errors.quote.not_offered')]]);
            }

            if ($locked->transport_order_id !== null) {
                $order = TmsTransportOrder::query()->findOrFail($locked->transport_order_id);
                $this->orders->assignCarrier($order, $carrierId, (string) $offer['total_amount']);
            }

            $locked->update([
                'status' => TmsQuote::Awarded,
                'awarded_carrier_id' => $carrierId,
                'awarded_amount' => $offer['total_amount'],
                'awarded_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    protected function assertDeletable(Model $record): void
    {
        /** @var TmsQuote $record */
        if ($record->status === TmsQuote::Awarded) {
            throw ValidationException::withMessages(['status' => [__('transportation_errors.quote.awarded_locked')]]);
        }
    }
}
