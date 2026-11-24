<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\BarcodeScannerServiceInterface;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\DTOs\BarcodeScanDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\WarehouseBin;

/**
 * The operations of a handheld barcode terminal. Codes arrive as scanned, and each scan carries the device and its own
 * id: a terminal that lost the answer can send the same scan again and gets the first answer back, with no second
 * movement.
 */
final class WarehouseTerminalService
{
    public function __construct(
        private readonly BarcodeScannerServiceInterface $scanner,
        private readonly DoubleEntryMovementServiceInterface $movements,
        private readonly InventoryCountServiceInterface $counts,
        private readonly PickConfirmationService $picking
    ) {}

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    public function receive(string $tenantId, string $userId, array $scan): array
    {
        return $this->once($tenantId, $userId, 'receipt', $scan, function () use ($scan, $userId): array {
            ['item' => $item, 'lot_number' => $scannedLot, 'expiry' => $scannedExpiry] = $this->resolveItem((string) $scan['item_code']);
            $bin = $this->resolveBin((string) $scan['bin_code']);
            $lot = $this->receiptLot($item, $scan['lot_number'] ?? $scannedLot, $scan['expiration_date'] ?? $scannedExpiry);

            if ($item->tracking_type === 'serial' && (empty($scan['serial_number']) || bccomp((string) $scan['quantity'], '1', 4) !== 0)) {
                throw new StockRuleViolation('A serialized item is received one unit at a time, with its serial number.');
            }

            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: $item->id,
                sourceBinId: null,
                destinationBinId: $bin->id,
                quantity: (float) $scan['quantity'],
                movementType: 'receipt',
                lotId: $lot?->id,
                originDocumentType: isset($scan['purchase_receipt_id']) ? 'purchase_receipts' : null,
                originDocumentId: $scan['purchase_receipt_id'] ?? null,
                userId: $userId
            ));

            if (! empty($scan['serial_number'])) {
                StockSerialNumber::create([
                    'tenant_id' => $item->tenant_id,
                    'stock_item_id' => $item->id,
                    'serial_number' => $scan['serial_number'],
                    'status' => 'in_stock',
                    'current_warehouse_bin_id' => $bin->id,
                    'lot_id' => $lot?->id,
                ]);
            }

            return ['movement_id' => $movement->id, 'stock_item_id' => $item->id, 'lot_id' => $lot?->id, 'warehouse_bin_id' => $bin->id];
        });
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    public function pick(string $tenantId, string $userId, array $scan): array
    {
        return $this->once($tenantId, $userId, 'pick', $scan, function () use ($scan, $userId): array {
            $line = StockPickLine::query()->findOrFail($scan['pick_line_id']);
            $bin = $this->resolveBin((string) $scan['bin_code']);
            ['item' => $item] = $this->resolveItem((string) $scan['item_code']);

            if ((string) $bin->id !== (string) $line->warehouse_bin_id) {
                throw new StockRuleViolation('Wrong bin: this line is picked from another location.');
            }

            if ((string) $item->id !== (string) $line->stock_item_id) {
                throw new StockRuleViolation('Wrong item: this line picks another product.');
            }

            if (! empty($scan['lot_number']) && $line->lot_id !== null && $line->lot?->lot_number !== $scan['lot_number']) {
                throw new StockRuleViolation('Wrong lot: this line picks another lot.');
            }

            $destination = ! empty($scan['destination_bin_code']) ? $this->resolveBin((string) $scan['destination_bin_code'])->id : null;
            $picked = $this->picking->confirm($line, (string) $scan['quantity'], $userId, $destination);

            return ['pick_line_id' => $picked->id, 'status' => $picked->status, 'quantity_picked' => (string) $picked->quantity_picked];
        });
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    public function transfer(string $tenantId, string $userId, array $scan): array
    {
        return $this->once($tenantId, $userId, 'transfer', $scan, function () use ($scan, $userId): array {
            ['item' => $item, 'lot_number' => $scannedLot] = $this->resolveItem((string) $scan['item_code']);
            $from = $this->resolveBin((string) $scan['from_bin_code']);
            $to = $this->resolveBin((string) $scan['to_bin_code']);
            $lotNumber = $scan['lot_number'] ?? $scannedLot;
            $lot = $lotNumber !== null ? $this->existingLot($item, (string) $lotNumber) : null;

            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: $item->id,
                sourceBinId: $from->id,
                destinationBinId: $to->id,
                quantity: (float) $scan['quantity'],
                movementType: 'internal_transfer',
                lotId: $lot?->id,
                userId: $userId
            ));

            return ['movement_id' => $movement->id];
        });
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    public function count(string $tenantId, string $userId, array $scan): array
    {
        return $this->once($tenantId, $userId, 'count', $scan, function () use ($scan, $userId): array {
            $session = InventoryCountSession::query()->findOrFail($scan['session_id']);

            if ($session->status !== 'in_progress') {
                throw new StockRuleViolation('This count session is closed.');
            }

            ['item' => $item, 'lot_number' => $scannedLot] = $this->resolveItem((string) $scan['item_code']);
            $bin = $this->resolveBin((string) $scan['bin_code']);
            $lotNumber = $scan['lot_number'] ?? $scannedLot;
            $lot = $lotNumber !== null ? $this->existingLot($item, (string) $lotNumber) : null;

            $line = InventoryCountLine::query()
                ->where('inventory_count_session_id', $session->id)
                ->where('warehouse_bin_id', $bin->id)
                ->where('stock_item_id', $item->id)
                ->where('lot_id', $lot?->id)
                ->first();

            $line ??= InventoryCountLine::create([
                'tenant_id' => $session->tenant_id,
                'inventory_count_session_id' => $session->id,
                'warehouse_bin_id' => $bin->id,
                'stock_item_id' => $item->id,
                'lot_id' => $lot?->id,
                'system_quantity' => 0,
            ]);

            $this->counts->recordCount((string) $line->id, (float) $scan['quantity'], $userId);

            return ['count_line_id' => $line->id, 'discrepancy_quantity' => (string) $line->fresh()->discrepancy_quantity];
        });
    }

    /**
     * Runs a scan the first time it is seen. The unique log row is written first, so a resend racing the original
     * waits for it and then reads its answer.
     *
     * @param  array<string, mixed>  $scan
     * @param  callable(): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function once(string $tenantId, string $userId, string $operation, array $scan, callable $run): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $operation, $scan, $run): array {
            $id = (string) Str::uuid7();
            $inserted = DB::table('stock_terminal_scans')->insertOrIgnore([
                'id' => $id,
                'tenant_id' => $tenantId,
                'device_id' => (string) $scan['device_id'],
                'client_scan_id' => (string) $scan['client_scan_id'],
                'operation' => $operation,
                'user_id' => $userId,
                'payload' => json_encode($scan, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                $stored = DB::table('stock_terminal_scans')
                    ->where('tenant_id', $tenantId)
                    ->where('device_id', (string) $scan['device_id'])
                    ->where('client_scan_id', (string) $scan['client_scan_id'])
                    ->value('result');

                return ['replayed' => true] + (array) json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
            }

            $result = $run();
            DB::table('stock_terminal_scans')->where('id', $id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return ['replayed' => false] + $result;
        });
    }

    private function resolveBin(string $code): WarehouseBin
    {
        $bin = WarehouseBin::query()->where('barcode', trim($code))->first();

        if ($bin === null) {
            throw new StockRuleViolation("Unknown location [{$code}].");
        }

        return $bin;
    }

    /**
     * @return array{item: StockItem, lot_number: ?string, expiry: ?string}
     */
    private function resolveItem(string $code): array
    {
        $parsed = $this->scanner->parseBarcode(new BarcodeScanDTO(rawBarcode: $code));

        $resolved = match ($parsed['type']) {
            'item' => ['item' => $parsed['entity'], 'lot_number' => null, 'expiry' => null],
            'lot' => ['item' => $parsed['entity']->item, 'lot_number' => $parsed['entity']->lot_number, 'expiry' => null],
            'serial_number' => ['item' => $parsed['entity']->item, 'lot_number' => $parsed['entity']->lot?->lot_number, 'expiry' => null],
            'gs1_composite' => $this->fromGs1($parsed['data']),
            default => null,
        };

        if ($resolved === null || $resolved['item'] === null) {
            throw new StockRuleViolation("Unknown item code [{$code}].");
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $gs1
     * @return array{item: ?StockItem, lot_number: ?string, expiry: ?string}
     */
    private function fromGs1(array $gs1): array
    {
        $gtin = (string) ($gs1['gtin'] ?? '');
        $item = $gtin === '' ? null : StockItem::query()->whereIn('barcode_ean13', [$gtin, ltrim($gtin, '0'), substr($gtin, 1)])->first();
        $expiry = isset($gs1['expiry_yymmdd']) ? CarbonImmutable::createFromFormat('!ymd', $gs1['expiry_yymmdd'])?->toDateString() : null;

        return ['item' => $item, 'lot_number' => $gs1['lot'] ?? null, 'expiry' => $expiry];
    }

    private function existingLot(StockItem $item, string $lotNumber): StockLot
    {
        $lot = StockLot::query()->where('stock_item_id', $item->id)->where('lot_number', $lotNumber)->first();

        if ($lot === null) {
            throw new StockRuleViolation("Unknown lot [{$lotNumber}] for item [{$item->sku}].");
        }

        return $lot;
    }

    private function receiptLot(StockItem $item, ?string $lotNumber, ?string $expiry): ?StockLot
    {
        if ($lotNumber === null || $lotNumber === '') {
            if ($item->tracking_type === 'lot') {
                throw new StockRuleViolation("Item [{$item->sku}] is received by lot.");
            }

            return null;
        }

        $today = CarbonImmutable::today();
        $expiry ??= $item->shelf_life_days !== null ? $today->addDays($item->shelf_life_days)->toDateString() : null;

        return StockLot::query()->firstOrCreate(
            ['stock_item_id' => $item->id, 'lot_number' => $lotNumber],
            ['tenant_id' => $item->tenant_id, 'company_id' => $item->company_id, 'expiration_date' => $expiry, 'received_at' => $today->toDateString()]
        );
    }
}
