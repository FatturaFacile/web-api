<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\WeighbridgeReading;
use Modules\Inventory\Models\WeighbridgeTicket;
use Modules\Inventory\Models\WeighbridgeVehicle;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;

/**
 * Weighing tickets: a vehicle crosses twice, or once when its stored tare is used. The heavier weight is the gross
 * whatever the direction, so loading and unloading work the same. A closed ticket is billed once on a delivery line,
 * in kg, tonnes, or cubic metres through the density of the load.
 */
final class WeighbridgeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function open(WeighbridgeVehicle $vehicle, array $data, string $userId): WeighbridgeTicket
    {
        return WeighbridgeTicket::create([
            'tenant_id' => $vehicle->tenant_id,
            'ticket_number' => 'WB-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'weighbridge_vehicle_id' => $vehicle->id,
            'contact_id' => $data['contact_id'] ?? $vehicle->contact_id,
            'stock_item_id' => $data['stock_item_id'] ?? null,
            'direction' => $data['direction'],
            'status' => 'open',
            'first_weight_kg' => $this->weightFrom($data),
            'first_weighed_at' => now(),
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function close(WeighbridgeTicket $ticket, array $data): WeighbridgeTicket
    {
        return DB::transaction(function () use ($ticket, $data): WeighbridgeTicket {
            $locked = WeighbridgeTicket::query()->with('vehicle')->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'open') {
                throw new StockRuleViolation('This ticket is already closed.');
            }

            $useStoredTare = (bool) ($data['use_stored_tare'] ?? false);
            $second = $useStoredTare ? (string) $locked->vehicle?->stored_tare_kg : $this->weightFrom($data);

            if ($useStoredTare && $locked->vehicle?->stored_tare_kg === null) {
                throw new StockRuleViolation('This vehicle has no stored tare.');
            }

            $first = (string) $locked->first_weight_kg;
            $firstIsHeavier = bccomp($first, $second, 3) >= 0;
            $gross = $firstIsHeavier ? $first : $second;
            $tare = $firstIsHeavier ? $second : $first;

            $locked->update([
                'second_weight_kg' => $second,
                'second_weighed_at' => now(),
                'used_stored_tare' => $useStoredTare,
                'gross_kg' => $gross,
                'tare_kg' => $tare,
                'net_kg' => bcsub($gross, $tare, 3),
                'status' => 'closed',
            ]);

            return $locked;
        });
    }

    /**
     * @param  array{delivery_note_item_id: string, billing_uom: string, density_kg_m3?: mixed}  $data
     */
    public function bill(WeighbridgeTicket $ticket, array $data): WeighbridgeTicket
    {
        return DB::transaction(function () use ($ticket, $data): WeighbridgeTicket {
            $locked = WeighbridgeTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'closed') {
                throw new StockRuleViolation('Only a closed, unbilled ticket can be billed.');
            }

            $line = SalesDeliveryNoteItem::query()->with('deliveryNote')->findOrFail($data['delivery_note_item_id']);

            if ($line->deliveryNote?->status === 'invoiced') {
                throw new StockRuleViolation('This delivery note is already invoiced.');
            }

            $quantity = $this->convert((string) $locked->net_kg, $data['billing_uom'], isset($data['density_kg_m3']) ? (string) $data['density_kg_m3'] : null);

            $line->update(['quantity' => $quantity, 'uom' => $data['billing_uom'], 'weighbridge_ticket_id' => $locked->id]);
            $locked->update([
                'status' => 'billed',
                'delivery_note_item_id' => $line->id,
                'billing_uom' => $data['billing_uom'],
                'density_kg_m3' => $data['density_kg_m3'] ?? null,
                'billed_quantity' => $quantity,
            ]);

            return $locked;
        });
    }

    private function convert(string $netKg, string $uom, ?string $density): string
    {
        return match ($uom) {
            'kg' => bcadd($netKg, '0', 4),
            't' => bcdiv($netKg, '1000', 4),
            'm3' => $density !== null && bccomp($density, '0', 3) > 0
                ? bcdiv($netKg, $density, 4)
                : throw new StockRuleViolation('Billing in m3 needs the density of the load.'),
            default => throw new StockRuleViolation("Unknown billing unit [{$uom}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function weightFrom(array $data): string
    {
        if (! empty($data['reading_id'])) {
            return (string) WeighbridgeReading::query()->findOrFail($data['reading_id'])->weight_kg;
        }

        if (! isset($data['weight_kg'])) {
            throw new StockRuleViolation('A weighing needs a weight or a device reading.');
        }

        return bcadd((string) $data['weight_kg'], '0', 3);
    }
}
