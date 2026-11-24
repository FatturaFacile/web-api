<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\DeliveryNote;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Support\Quantity;

/**
 * Recall of a lot: everything that must be found when a lot turns out to be defective.
 *
 * Forward from the lot through its genealogy (LotTraceabilityService) it lists every affected lot, where their stock
 * still is (bin by bin, per warehouse), the customers and delivery notes that received them (by the batch number on
 * the delivery note lines and by the lot of the stock movements issued for a delivery note), and the other documents
 * that took stock of them out. Optionally it quarantines every affected lot, which blocks their stock for orders,
 * picks and sales at once.
 */
final readonly class LotRecallService
{
    public function __construct(private LotTraceabilityService $traceability) {}

    /**
     * @return array{lot_id: string, lot_number: string, quarantined: bool, affected_lots: list<array<string, mixed>>, customers: list<array{contact_id: string, delivery_notes: list<string>}>, totals: array{lots: int, on_hand: string, delivered: string}}
     */
    public function report(StockLot $lot, bool $quarantine = false): array
    {
        $lots = $this->affectedLots($this->traceability->forward($lot));

        if ($quarantine) {
            DB::transaction(static function () use ($lots): void {
                StockLot::query()->whereIn('id', array_keys($lots))->update(['is_quarantined' => true]);
            });
        }

        $affected = [];
        $customers = [];
        $onHandTotal = '0';
        $deliveredTotal = '0';

        foreach (StockLot::query()->with('item')->whereIn('id', array_keys($lots))->orderBy('lot_number')->get() as $affectedLot) {
            $stock = StockQuant::query()
                ->with('bin.rack.aisle.zone')
                ->where('lot_id', $affectedLot->id)
                ->where('quantity_on_hand', '>', 0)
                ->orderBy('id')
                ->get()
                ->map(static fn (StockQuant $quant): array => [
                    'warehouse_id' => $quant->bin?->rack?->aisle?->zone?->warehouse_id,
                    'warehouse_bin_id' => (string) $quant->warehouse_bin_id,
                    'bin_barcode' => $quant->bin?->barcode,
                    'on_hand' => Quantity::of((string) $quant->quantity_on_hand),
                    'reserved' => Quantity::of((string) $quant->quantity_reserved),
                ])
                ->all();

            $deliveries = $this->deliveries($affectedLot, $lots[(string) $affectedLot->id]);
            $issues = StockMovement::query()
                ->where('lot_id', $affectedLot->id)
                ->whereNotNull('source_location_id')
                ->whereNull('destination_location_id')
                ->orderBy('executed_at')
                ->get(['id', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'origin_document_type', 'origin_document_id', 'executed_at'])
                ->map(static fn (StockMovement $movement): array => [
                    'movement_id' => (string) $movement->id,
                    'movement_type' => (string) $movement->movement_type,
                    'quantity' => Quantity::of((string) $movement->quantity),
                    'document_type' => $movement->reference_type ?? $movement->origin_document_type,
                    'document_id' => $movement->reference_id ?? $movement->origin_document_id,
                    'executed_at' => $movement->executed_at?->toIso8601String(),
                ])
                ->all();

            foreach ($deliveries as $delivery) {
                $customers[$delivery['contact_id']][$delivery['delivery_note_id']] = true;
                $deliveredTotal = Quantity::add($deliveredTotal, $delivery['quantity']);
            }

            $onHand = array_reduce($stock, static fn (string $sum, array $row): string => Quantity::add($sum, $row['on_hand']), '0');
            $onHandTotal = Quantity::add($onHandTotal, $onHand);

            $affected[] = [
                'lot_id' => (string) $affectedLot->id,
                'lot_number' => $affectedLot->lot_number,
                'stock_item_id' => (string) $affectedLot->stock_item_id,
                'sku' => $affectedLot->item?->sku,
                'expiration_date' => $affectedLot->expiration_date?->toDateString(),
                'is_quarantined' => (bool) $affectedLot->is_quarantined,
                'on_hand' => $onHand,
                'stock' => $stock,
                'deliveries' => $deliveries,
                'issues' => $issues,
            ];
        }

        return [
            'lot_id' => (string) $lot->id,
            'lot_number' => (string) $lot->lot_number,
            'quarantined' => $quarantine,
            'affected_lots' => $affected,
            'customers' => collect($customers)->map(static fn (array $notes, string $contactId): array => ['contact_id' => $contactId, 'delivery_notes' => array_keys($notes)])->values()->all(),
            'totals' => ['lots' => count($affected), 'on_hand' => $onHandTotal, 'delivered' => $deliveredTotal],
        ];
    }

    /**
     * Flattens the forward genealogy tree into the affected lots, keeping the deliveries found by batch number.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, list<array<string, string>>>  $lots
     * @return array<string, list<array<string, string>>>
     */
    private function affectedLots(array $node, array $lots = []): array
    {
        $lots[(string) $node['lot_id']] = array_values((array) ($node['deliveries'] ?? []));

        foreach ((array) ($node['lots'] ?? []) as $child) {
            $lots = $this->affectedLots($child, $lots);
        }

        return $lots;
    }

    /**
     * Delivery notes that carried the lot: by batch number (from the genealogy) and by the lot of the stock issued for a
     * delivery note.
     *
     * @param  list<array<string, string>>  $byBatch
     * @return list<array{delivery_note_id: string, ddt_number: string, contact_id: string, date: string, quantity: string}>
     */
    private function deliveries(StockLot $lot, array $byBatch): array
    {
        $deliveries = [];

        foreach ($byBatch as $delivery) {
            $deliveries[$delivery['delivery_note_id']] = [
                'delivery_note_id' => $delivery['delivery_note_id'],
                'ddt_number' => $delivery['ddt_number'],
                'contact_id' => $delivery['contact_id'],
                'date' => substr($delivery['date'], 0, 10),
                'quantity' => Quantity::of($delivery['quantity']),
            ];
        }

        $morph = (new DeliveryNote)->getMorphClass();
        $issued = StockMovement::query()
            ->where('lot_id', $lot->id)
            ->where('reference_type', $morph)
            ->whereNotNull('source_location_id')
            ->whereNull('destination_location_id')
            ->groupBy('reference_id')
            ->selectRaw('reference_id, SUM(quantity) AS quantity')
            ->toBase()
            ->get();

        $notes = DeliveryNote::query()->whereIn('id', $issued->pluck('reference_id'))->get()->keyBy('id');

        foreach ($issued as $row) {
            $note = $notes->get($row->reference_id);

            if ($note === null || isset($deliveries[(string) $note->id])) {
                continue;
            }

            $deliveries[(string) $note->id] = [
                'delivery_note_id' => (string) $note->id,
                'ddt_number' => (string) $note->ddt_number,
                'contact_id' => (string) $note->contact_id,
                'date' => substr((string) $note->getRawOriginal('date'), 0, 10),
                'quantity' => Quantity::of((string) $row->quantity),
            ];
        }

        return array_values($deliveries);
    }
}
