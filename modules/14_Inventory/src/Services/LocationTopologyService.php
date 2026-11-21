<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Enums\BarcodeType;
use App\Services\Inventory\BarcodeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;

/**
 * Read models and batch tools over the location hierarchy (warehouse > zone > aisle > rack > shelf > bin): the tree for
 * the topology explorer, per-zone capacity, the bulk generator that lays out a whole zone in one transaction, location
 * labels (ZPL and printable HTML sheets) and the stock currently in transit between warehouses.
 */
final readonly class LocationTopologyService
{
    /** Most bins one bulk generation may create, so a typo in a range cannot flood the master data. */
    public const int MAX_BULK_BINS = 5000;

    public function __construct(
        private WarehouseLocationService $locations,
        private WarehouseCapacityService $capacity,
        private BarcodeService $barcodes
    ) {}

    /**
     * The hierarchy of one warehouse as nested nodes; the bins are leaves carrying their occupancy.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(Warehouse $warehouse): array
    {
        $bins = WarehouseBin::query()
            ->with('rack.aisle.zone')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouse->id))
            ->orderBy('code')
            ->get();
        $described = collect($this->capacity->describe($bins))->keyBy('id');
        /** @var array<string, array<string, mixed>> $zoneNodes */
        $zoneNodes = [];
        /** @var array<string, array<string, array<string, mixed>>> $aisleNodes */
        $aisleNodes = [];
        /** @var array<string, array<string, array<string, array<string, mixed>>>> $rackNodes */
        $rackNodes = [];
        /** @var array<string, array<string, array<string, list<array<string, mixed>>>>> $binNodes */
        $binNodes = [];

        foreach ($bins as $bin) {
            $rack = $bin->rack;
            $aisle = $rack?->aisle;
            $zone = $aisle?->zone;

            if ($rack === null || $aisle === null || $zone === null) {
                continue;
            }

            $info = $described->get((string) $bin->id, []);
            $zoneNodes[$zone->code] ??= ['type' => 'zone', 'id' => (string) $zone->id, 'code' => $zone->code, 'name' => $zone->name, 'zone_type' => $zone->zone_type, 'temperature_control' => $zone->temperature_control ?? 'ambient'];
            $aisleNodes[$zone->code][$aisle->code] ??= ['type' => 'aisle', 'id' => (string) $aisle->id, 'code' => $aisle->code];
            $rackNodes[$zone->code][$aisle->code][$rack->code] ??= ['type' => 'rack', 'id' => (string) $rack->id, 'code' => $rack->code];
            $binNodes[$zone->code][$aisle->code][$rack->code][] = [
                'type' => 'bin',
                'id' => (string) $bin->id,
                'code' => $bin->code,
                'shelf_code' => $bin->shelf_code,
                'full_path_code' => $bin->full_path_code,
                'barcode' => $bin->barcode,
                'bin_type' => $bin->bin_type ?? 'storage',
                'is_locked' => (bool) $bin->is_locked,
                'block_reason' => $bin->block_reason,
                'occupancy' => $info['occupancy'] ?? null,
                'weight_utilization_pct' => $this->pct($info['occupancy']['weight_kg'] ?? null, $bin->max_weight_kg),
                'volume_utilization_pct' => $this->pct($info['occupancy']['volume_m3'] ?? null, $bin->max_volume_cbm),
            ];
        }

        $out = [];

        foreach ($zoneNodes as $zoneCode => $zoneNode) {
            $aislesOut = [];

            foreach ($aisleNodes[$zoneCode] as $aisleCode => $aisleNode) {
                $racksOut = [];

                foreach ($rackNodes[$zoneCode][$aisleCode] as $rackCode => $rackNode) {
                    $racksOut[] = $rackNode + ['children' => $binNodes[$zoneCode][$aisleCode][$rackCode]];
                }

                $aislesOut[] = $aisleNode + ['children' => $racksOut];
            }

            $out[] = $zoneNode + ['children' => $aislesOut];
        }

        return $out;
    }

    /**
     * Weight, volume and unit utilisation of every zone of the warehouse, from the declared bin limits and the quants.
     *
     * @return list<array<string, mixed>>
     */
    public function capacitySummary(Warehouse $warehouse): array
    {
        $bins = WarehouseBin::query()
            ->with('rack.aisle.zone')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouse->id))
            ->get();
        $occupancy = $this->capacity->occupancy($bins->map(static fn (WarehouseBin $bin): string => (string) $bin->id)->all());
        /** @var array<string, array{zone_id: string, zone_code: string, bins: int, blocked_bins: int, occupied_bins: int, weight_kg: float, max_weight_kg: float, volume_m3: float, max_volume_m3: float}> $totals */
        $totals = [];

        foreach ($bins as $bin) {
            $zone = $bin->rack?->aisle?->zone;

            if ($zone === null) {
                continue;
            }

            $held = $occupancy[(string) $bin->id];
            $key = (string) $zone->id;
            $row = $totals[$key] ?? ['zone_id' => $key, 'zone_code' => (string) $zone->code, 'bins' => 0, 'blocked_bins' => 0, 'occupied_bins' => 0, 'weight_kg' => 0.0, 'max_weight_kg' => 0.0, 'volume_m3' => 0.0, 'max_volume_m3' => 0.0];
            $totals[$key] = [
                'zone_id' => $row['zone_id'],
                'zone_code' => $row['zone_code'],
                'bins' => $row['bins'] + 1,
                'blocked_bins' => $row['blocked_bins'] + ($bin->is_locked ? 1 : 0),
                'occupied_bins' => $row['occupied_bins'] + ((float) $held['quantity'] > 0 ? 1 : 0),
                'weight_kg' => $row['weight_kg'] + (float) $held['weight_kg'],
                'max_weight_kg' => $row['max_weight_kg'] + (float) ($bin->max_weight_kg ?? 0),
                'volume_m3' => $row['volume_m3'] + (float) $held['volume_m3'],
                'max_volume_m3' => $row['max_volume_m3'] + (float) ($bin->max_volume_cbm ?? 0),
            ];
        }

        $summary = [];

        foreach ($totals as $row) {
            $summary[] = $row + [
                'weight_utilization_pct' => $this->pct($row['weight_kg'], $row['max_weight_kg'] > 0 ? $row['max_weight_kg'] : null),
                'volume_utilization_pct' => $this->pct($row['volume_m3'], $row['max_volume_m3'] > 0 ? $row['max_volume_m3'] : null),
            ];
        }

        return $summary;
    }

    /**
     * Lays out a zone: aisles A01.., racks R01.., shelves S01.. and bins B01.. in one transaction. Existing barcodes are
     * refused up front, so the batch either creates everything or nothing.
     *
     * @param  array{zone_code: string, zone_name?: ?string, zone_type?: ?string, aisles: int, racks: int, shelves: int, bins: int, max_weight_kg?: mixed, max_volume_cbm?: mixed, max_quantity?: mixed, length_cm?: mixed, width_cm?: mixed, height_cm?: mixed, spacing?: mixed}  $spec
     * @return list<WarehouseBin>
     *
     * @throws StockRuleViolation when the layout is too large or collides with an existing barcode
     */
    public function bulkGenerate(Warehouse $warehouse, array $spec): array
    {
        $total = $spec['aisles'] * $spec['racks'] * $spec['shelves'] * $spec['bins'];

        if ($total > self::MAX_BULK_BINS) {
            throw new StockRuleViolation('A bulk generation creates at most '.self::MAX_BULK_BINS." bins; this layout would create {$total}.");
        }

        $spacing = (float) ($spec['spacing'] ?? 2.0);
        $plan = [];

        for ($a = 1; $a <= $spec['aisles']; $a++) {
            for ($r = 1; $r <= $spec['racks']; $r++) {
                for ($s = 1; $s <= $spec['shelves']; $s++) {
                    for ($b = 1; $b <= $spec['bins']; $b++) {
                        $codes = [sprintf('A%02d', $a), sprintf('R%02d', $r), sprintf('S%02d', $s), sprintf('B%02d', $b)];
                        $plan[] = ['codes' => $codes, 'barcode' => implode('-', [$warehouse->code, $spec['zone_code'], ...$codes]), 'x' => $a * $spacing * 3, 'y' => $r * $spacing, 'z' => ($s - 1) * 1.5];
                    }
                }
            }
        }

        $clash = WarehouseBin::query()->whereIn('barcode', array_column($plan, 'barcode'))->pluck('barcode')->all();

        if ($clash !== []) {
            throw new StockRuleViolation('Bin barcodes already exist: '.implode(', ', array_slice($clash, 0, 5)).(count($clash) > 5 ? ', ...' : '').'.');
        }

        return DB::transaction(function () use ($warehouse, $spec, $plan): array {
            $created = [];

            foreach ($plan as $slot) {
                [$aisle, $rack, $shelf, $bin] = $slot['codes'];
                $created[] = $this->locations->addBin($warehouse, [
                    'zone_code' => $spec['zone_code'],
                    'zone_name' => $spec['zone_name'] ?? null,
                    'zone_type' => $spec['zone_type'] ?? 'storage',
                    'aisle_code' => $aisle,
                    'rack_code' => $rack,
                    'shelf_code' => $shelf,
                    'bin_code' => $bin,
                    'barcode' => $slot['barcode'],
                    'max_weight_kg' => $spec['max_weight_kg'] ?? null,
                    'max_volume_cbm' => $spec['max_volume_cbm'] ?? null,
                    'max_quantity' => $spec['max_quantity'] ?? null,
                    'length_cm' => $spec['length_cm'] ?? null,
                    'width_cm' => $spec['width_cm'] ?? null,
                    'height_cm' => $spec['height_cm'] ?? null,
                    'coord_x' => $slot['x'],
                    'coord_y' => $slot['y'],
                    'coord_z' => $slot['z'],
                ]);
            }

            return $created;
        });
    }

    /**
     * Raw ZPL for the bins, one label (^XA..^XZ) each: human-readable path, Code128 and QR of the barcode. Sizes are in
     * millimetres at 203 dpi (8 dots per mm).
     *
     * @param  Collection<int, WarehouseBin>  $bins
     */
    public function zpl(Collection $bins, int $widthMm = 100, int $heightMm = 50): string
    {
        $w = $widthMm * 8;
        $h = $heightMm * 8;
        $labels = [];

        foreach ($bins as $bin) {
            $path = str_replace('-', ' > ', (string) ($bin->full_path_code ?? $bin->code));
            $labels[] = implode("\n", [
                '^XA',
                "^PW{$w}",
                "^LL{$h}",
                '^CI28',
                '^FO20,20^A0N,'.max(24, (int) ($h / 6)).",0^FD{$this->zplText($path)}^FS",
                '^FO20,'.(int) ($h * 0.30).'^BY2^BCN,'.(int) ($h * 0.30).',Y,N,N^FD'.$this->zplText((string) $bin->barcode).'^FS',
                '^FO'.($w - (int) ($h * 0.45) - 20).','.(int) ($h * 0.10).'^BQN,2,'.max(3, (int) ($h / 90)).'^FDQA,'.$this->zplText((string) $bin->barcode).'^FS',
                '^XZ',
            ]);
        }

        return implode("\n", $labels)."\n";
    }

    /**
     * A printable HTML sheet (A4 grid) of the labels, with an SVG Code128 and QR code each; rendered to PDF by the
     * controller.
     *
     * @param  Collection<int, WarehouseBin>  $bins
     */
    public function sheetHtml(Collection $bins, int $widthMm = 100, int $heightMm = 50): string
    {
        $cells = '';

        foreach ($bins as $bin) {
            $path = e(str_replace('-', ' > ', (string) ($bin->full_path_code ?? $bin->code)));
            $code128 = $this->barcodes->pngDataUri(BarcodeType::Code128, (string) $bin->barcode, 2, 40);
            $qr = $this->barcodes->pngDataUri(BarcodeType::QrCode, (string) $bin->barcode, 4, 40);
            $cells .= "<div class=\"label\"><div class=\"path\">{$path}</div><img class=\"c128\" src=\"{$code128}\"><img class=\"qr\" src=\"{$qr}\"><div class=\"code\">".e((string) $bin->barcode).'</div></div>';
        }

        return "<html><head><meta charset=\"utf-8\"><style>@page{margin:8mm}body{font-family:DejaVu Sans,sans-serif}.label{display:inline-block;vertical-align:top;box-sizing:border-box;width:{$widthMm}mm;height:{$heightMm}mm;border:0.2mm dashed #999;padding:2mm;position:relative}.path{font-size:11pt;font-weight:bold}.c128{width:62%;height:40%;margin-top:3mm}.qr{position:absolute;right:3mm;top:8mm;width:30%}.code{font-size:8pt;margin-top:1mm}</style></head><body>{$cells}</body></html>";
    }

    /**
     * Stock shipped between warehouses and not yet fully received, per transfer line.
     *
     * @return list<array<string, mixed>>
     */
    public function inTransit(?string $destinationWarehouseId = null): array
    {
        $orders = TransferOrder::query()
            ->whereIn('status', [TransferStatus::InTransit->value, TransferStatus::PartiallyReceived->value, TransferStatus::DiscrepancyPending->value])
            ->when($destinationWarehouseId !== null, static fn ($query) => $query->where('destination_warehouse_id', $destinationWarehouseId))
            ->with(['lines.item'])
            ->orderBy('shipped_at')
            ->get();
        $rows = [];

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                /** @var TransferOrderLine $line */
                $outstanding = (float) $line->quantity_shipped - (float) $line->quantity_received - (float) ($line->quantity_damaged ?? 0);
                $rows[] = [
                    'transfer_order_id' => (string) $order->id,
                    'transfer_number' => $order->transfer_number,
                    'status' => (string) $order->status,
                    'source_warehouse_id' => (string) $order->source_warehouse_id,
                    'destination_warehouse_id' => (string) $order->destination_warehouse_id,
                    'transit_bin_id' => $order->transit_bin_id,
                    'line_id' => (string) $line->id,
                    'stock_item_id' => (string) $line->stock_item_id,
                    'sku' => $line->item?->sku,
                    'lot_id' => $line->lot_id,
                    'shipped_qty' => (string) $line->quantity_shipped,
                    'received_qty' => (string) $line->quantity_received,
                    'damaged_qty' => (string) ($line->quantity_damaged ?? '0'),
                    'outstanding_qty' => number_format(max(0.0, $outstanding), 4, '.', ''),
                    'shipped_at' => $order->shipped_at?->toIso8601String(),
                ];
            }
        }

        return $rows;
    }

    private function pct(mixed $held, mixed $max): ?float
    {
        if ($held === null || $max === null || (float) $max <= 0.0) {
            return null;
        }

        return round((float) $held / (float) $max * 100, 1);
    }

    private function zplText(string $value): string
    {
        return str_replace(['^', '~', '\\'], ['', '', ''], $value);
    }
}
