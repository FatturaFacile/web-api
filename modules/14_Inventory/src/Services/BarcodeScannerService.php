<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Contracts\BarcodeScannerServiceInterface;
use Modules\Inventory\DTOs\BarcodeScanDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\WarehouseBin;

final class BarcodeScannerService implements BarcodeScannerServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function parseBarcode(BarcodeScanDTO $dto): array
    {
        $raw = trim($dto->rawBarcode);

        // 1. Check if it's a Warehouse Bin barcode
        $bin = WarehouseBin::query()
            ->where('barcode', $raw)
            ->with('rack.aisle.zone.warehouse')
            ->first();

        if ($bin) {
            return [
                'type' => 'bin',
                'entity' => $bin,
                'data' => [
                    'bin_id' => $bin->id,
                    'bin_code' => $bin->code,
                    'warehouse_id' => $bin->rack?->aisle?->zone?->warehouse_id,
                ],
            ];
        }

        // 2. Check if it matches a direct Serial Number
        $serial = StockSerialNumber::query()
            ->where('serial_number', $raw)
            ->with(['item', 'bin', 'lot'])
            ->first();

        if ($serial) {
            return [
                'type' => 'serial_number',
                'entity' => $serial,
                'data' => [
                    'serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'stock_item_id' => $serial->stock_item_id,
                    'status' => $serial->status,
                ],
            ];
        }

        // 3. Check if it matches a Stock Lot / Batch Number
        $lot = StockLot::query()
            ->where('lot_number', $raw)
            ->orWhere('supplier_lot_number', $raw)
            ->with('item')
            ->first();

        if ($lot) {
            return [
                'type' => 'lot',
                'entity' => $lot,
                'data' => [
                    'lot_id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'stock_item_id' => $lot->stock_item_id,
                    'expiration_date' => $lot->expiration_date?->toDateString(),
                ],
            ];
        }

        // 4. Check if it matches a Product / Stock Item (EAN-13, SKU, custom barcode)
        $item = StockItem::query()
            ->where('barcode_ean13', $raw)
            ->orWhere('sku', $raw)
            ->orWhere('barcode_custom', $raw)
            ->first();

        if ($item) {
            return [
                'type' => 'item',
                'entity' => $item,
                'data' => [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'uom' => $item->uom,
                    'tracking_type' => $item->tracking_type,
                    'total_available' => $item->total_available,
                ],
            ];
        }

        // 5. GS1-128 Parsing: check for Application Identifiers
        if (str_contains($raw, '(01)') || str_starts_with($raw, '01')) {
            $parsed = $this->parseGs1Composite($raw);
            if (! empty($parsed)) {
                return [
                    'type' => 'gs1_composite',
                    'data' => $parsed,
                ];
            }
        }

        return [
            'type' => 'unknown',
            'raw' => $raw,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseGs1Composite(string $raw): array
    {
        $result = [];
        // Match (01)GTIN, (10)LOT, (17)EXPIRY, (21)SERIAL
        if (preg_match('/\(01\)(\d{14})/', $raw, $matches)) {
            $result['gtin'] = $matches[1];
        }
        if (preg_match('/\(10\)([A-Za-z0-9\-_]+)/', $raw, $matches)) {
            $result['lot'] = $matches[1];
        }
        if (preg_match('/\(17\)(\d{6})/', $raw, $matches)) {
            $result['expiry_yymmdd'] = $matches[1];
        }
        if (preg_match('/\(21\)([A-Za-z0-9\-_]+)/', $raw, $matches)) {
            $result['serial'] = $matches[1];
        }

        return $result;
    }
}
