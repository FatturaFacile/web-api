<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * Resolves what a handheld scanned into the records it means, with the stock behind each: an item (by GTIN-8/12/13/14
 * on the item or its catalog product, by SKU or by internal barcode), a bin (its contents), a lot (its item, expiry,
 * quarantine and stock) or a serial number (its item, status and bin). GS1-128 strings are decoded first (AI 01 GTIN,
 * 10 lot, 17 expiry, 21 serial; with parentheses or with the FNC1/GS separator) and each element is resolved. A code
 * can match several records (e.g. a lot number equal to a SKU): every match is returned.
 */
final readonly class BarcodeLookupService
{
    private const string GROUP_SEPARATOR = "\x1D";

    /**
     * @return array{code: string, gs1: array<string, string>, matches: list<array<string, mixed>>}
     */
    public function lookup(string $code, ?string $warehouseId = null): array
    {
        $code = trim($code);
        $gs1 = $this->parseGs1($code);
        $matches = [];

        if ($gs1 !== []) {
            if (isset($gs1['gtin'])) {
                array_push($matches, ...$this->items($this->gtinVariants($gs1['gtin']), $warehouseId));
            }

            if (isset($gs1['lot'])) {
                array_push($matches, ...$this->lots($gs1['lot'], $warehouseId));
            }

            if (isset($gs1['serial'])) {
                array_push($matches, ...$this->serials($gs1['serial']));
            }
        } else {
            $codes = ctype_digit($code) && in_array(strlen($code), [8, 12, 13, 14], true) ? $this->gtinVariants($code) : [$code];
            array_push($matches, ...$this->bins($code), ...$this->items($codes, $warehouseId), ...$this->lots($code, $warehouseId), ...$this->serials($code));
        }

        return ['code' => $code, 'gs1' => $gs1, 'matches' => $matches];
    }

    /**
     * Decodes the GS1 application identifiers the warehouse uses. Returns an empty array for a code that is not GS1.
     *
     * @return array<string, string>
     */
    public function parseGs1(string $raw): array
    {
        $result = [];

        if (str_contains($raw, '(')) {
            foreach (['01' => 'gtin', '10' => 'lot', '17' => 'expiry', '21' => 'serial'] as $ai => $key) {
                if (preg_match('/\('.$ai.'\)([^()]+)/', $raw, $match) === 1) {
                    $result[$key] = trim($match[1]);
                }
            }

            return $this->withExpiryDate($result);
        }

        // Scanners may prefix the symbology identifier of GS1-128 (]C1) and a leading FNC1.
        $rest = ltrim(str_starts_with($raw, ']C1') ? substr($raw, 3) : $raw, self::GROUP_SEPARATOR);

        if (! str_starts_with($rest, '01') || strlen($rest) < 16 || ! ctype_digit(substr($rest, 2, 14))) {
            return [];
        }

        while ($rest !== '') {
            $ai = substr($rest, 0, 2);
            $rest = substr($rest, 2);

            if ($ai === '01' || $ai === '17') {
                $length = $ai === '01' ? 14 : 6;
                $result[$ai === '01' ? 'gtin' : 'expiry'] = substr($rest, 0, $length);
                $rest = substr($rest, $length);
            } elseif ($ai === '10' || $ai === '21') {
                $end = strpos($rest, self::GROUP_SEPARATOR);
                $result[$ai === '10' ? 'lot' : 'serial'] = $end === false ? $rest : substr($rest, 0, $end);
                $rest = $end === false ? '' : substr($rest, $end + 1);
            } else {
                break;
            }

            $rest = ltrim($rest, self::GROUP_SEPARATOR);
        }

        return $this->withExpiryDate($result);
    }

    /**
     * @param  array<string, string>  $result
     * @return array<string, string>
     */
    private function withExpiryDate(array $result): array
    {
        if (isset($result['expiry']) && preg_match('/^(\d{2})(\d{2})(\d{2})$/', $result['expiry'], $parts) === 1) {
            // GS1 general specifications: YYMMDD, day 00 meaning the last day of the month.
            $year = 2000 + (int) $parts[1];
            $month = (int) $parts[2];
            $day = (int) $parts[3] === 0 ? (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year)) : (int) $parts[3];

            if (checkdate($month, $day, $year)) {
                $result['expiry_date'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return $result;
    }

    /**
     * A GTIN in its usual stored forms: as scanned, without the leading zeros of a GTIN-14, as EAN-13 and as UPC-A.
     *
     * @return list<string>
     */
    private function gtinVariants(string $gtin): array
    {
        $trimmed = ltrim($gtin, '0');

        return array_values(array_unique(array_filter([
            $gtin,
            strlen($gtin) === 14 ? substr($gtin, 1) : null,
            str_pad($trimmed, 13, '0', STR_PAD_LEFT),
            str_pad($trimmed, 12, '0', STR_PAD_LEFT),
            str_pad($trimmed, 8, '0', STR_PAD_LEFT),
        ], static fn (?string $value): bool => $value !== null && $value !== '')));
    }

    /**
     * @param  list<string>  $codes
     * @return list<array<string, mixed>>
     */
    private function items(array $codes, ?string $warehouseId): array
    {
        $productIds = Product::query()->whereIn('barcode', $codes)->pluck('id');

        return StockItem::query()
            ->where(static fn ($query) => $query->whereIn('barcode_ean13', $codes)->orWhereIn('sku', $codes)->orWhereIn('barcode_custom', $codes)->orWhereIn('product_id', $productIds))
            ->orderBy('sku')
            ->get()
            ->map(fn (StockItem $item): array => [
                'type' => 'item',
                'stock_item_id' => (string) $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'uom' => $item->uom,
                'tracking_type' => $item->tracking_type,
                'stock' => $this->stock(StockQuant::query()->where('stock_item_id', $item->id), $warehouseId),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bins(string $code): array
    {
        return WarehouseBin::query()
            ->with('rack.aisle.zone')
            ->where('barcode', $code)
            ->get()
            ->map(fn (WarehouseBin $bin): array => [
                'type' => 'bin',
                'warehouse_bin_id' => (string) $bin->id,
                'warehouse_id' => $bin->rack?->aisle?->zone?->warehouse_id,
                'code' => $bin->code,
                'barcode' => $bin->barcode,
                'bin_type' => $bin->bin_type,
                'zone_type' => $bin->rack?->aisle?->zone?->zone_type,
                'is_locked' => (bool) $bin->is_locked,
                'stock' => $this->stock(StockQuant::query()->where('warehouse_bin_id', $bin->id), null),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lots(string $code, ?string $warehouseId): array
    {
        return StockLot::query()
            ->with('item')
            ->where(static fn ($query) => $query->where('lot_number', $code)->orWhere('supplier_lot_number', $code))
            ->orderBy('lot_number')
            ->get()
            ->map(fn (StockLot $lot): array => [
                'type' => 'lot',
                'lot_id' => (string) $lot->id,
                'lot_number' => $lot->lot_number,
                'supplier_lot_number' => $lot->supplier_lot_number,
                'stock_item_id' => (string) $lot->stock_item_id,
                'sku' => $lot->item?->sku,
                'expiration_date' => $lot->expiration_date?->toDateString(),
                'is_quarantined' => (bool) $lot->is_quarantined,
                'stock' => $this->stock(StockQuant::query()->where('lot_id', $lot->id), $warehouseId),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serials(string $code): array
    {
        return StockSerialNumber::query()
            ->with(['item'])
            ->where('serial_number', $code)
            ->get()
            ->map(static fn (StockSerialNumber $serial): array => [
                'type' => 'serial_number',
                'serial_number_id' => (string) $serial->id,
                'serial_number' => $serial->serial_number,
                'stock_item_id' => (string) $serial->stock_item_id,
                'sku' => $serial->item?->sku,
                'status' => $serial->status,
                'warehouse_bin_id' => $serial->current_warehouse_bin_id,
                'lot_id' => $serial->lot_id,
            ])
            ->all();
    }

    /**
     * @param  Builder<StockQuant>  $quants
     * @return list<array{warehouse_id: ?string, warehouse_bin_id: string, bin_barcode: ?string, stock_item_id: string, lot_id: ?string, on_hand: string, reserved: string, available: string}>
     */
    private function stock(Builder $quants, ?string $warehouseId): array
    {
        /** @var Collection<int, StockQuant> $rows */
        $rows = $quants
            ->with('bin.rack.aisle.zone')
            ->where('quantity_on_hand', '>', 0)
            ->when($warehouseId !== null, static fn ($query) => $query->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId)))
            ->orderBy('id')
            ->get();

        return $rows->map(static fn (StockQuant $quant): array => [
            'warehouse_id' => $quant->bin?->rack?->aisle?->zone?->warehouse_id,
            'warehouse_bin_id' => (string) $quant->warehouse_bin_id,
            'bin_barcode' => $quant->bin?->barcode,
            'stock_item_id' => (string) $quant->stock_item_id,
            'lot_id' => $quant->lot_id,
            'on_hand' => Quantity::of((string) $quant->quantity_on_hand),
            'reserved' => Quantity::of((string) $quant->quantity_reserved),
            'available' => Quantity::of(StockAllocationPlanner::available($quant)),
        ])->values()->all();
    }
}
