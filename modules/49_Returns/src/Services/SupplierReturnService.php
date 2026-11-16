<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\GoodsReceiptItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\Supplier;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\RmaLine;
use Modules\Returns\Models\SupplierReturn;
use Modules\Returns\Models\SupplierReturnLine;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Returns to the supplier (RTV). A return is drafted for a supplier with its lines, shipped (the goods leave the
 * warehouse through the stock service of the inventory module), credited when the supplier's credit arrives and closed.
 * A line tied to a goods receipt line cannot exceed what that receipt brought in less what other returns took back.
 */
final readonly class SupplierReturnService
{
    public function __construct(
        private ReturnsNumbering $numbering,
        private ReturnsStockBooker $stock,
        private ReturnsSettingsService $settings,
        private ReturnsLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): SupplierReturn
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): SupplierReturn {
            $this->assertSupplier($tenantId, (string) $data['supplier_id']);

            if (! empty($data['reason_code'])) {
                $this->settings->assertActiveReason($tenantId, (string) $data['reason_code']);
            }

            $lines = $this->resolveLines($tenantId, $data['lines'], null);
            $return = SupplierReturn::create([
                'tenant_id' => $tenantId,
                'return_number' => $this->numbering->next(SupplierReturn::class, 'return_number', 'RTV', $tenantId),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'rma_id' => $data['rma_id'] ?? null,
                'status' => SupplierReturn::Draft,
                'reason_code' => $data['reason_code'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'currency' => $data['currency'] ?? 'EUR',
                'credit_expected' => $data['credit_expected'] ?? $this->linesTotal($lines),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $this->saveLines($return, $lines);
            $this->ledger->record('returns.supplier_return_created', $return, ['status' => $return->status]);

            return $return->load('lines');
        });
    }

    /**
     * Drafts the return to a supplier of the goods a customer return sent to that supplier (disposition
     * return_to_supplier). The goods are already in the warehouse by then.
     */
    public function draftFromRma(Rma $rma, string $supplierId, string $warehouseId, string $reasonCode, ?string $userId): SupplierReturn
    {
        $lines = $rma->lines()
            ->where('disposition', RmaLine::ReturnToSupplier)
            ->where('supplier_id', $supplierId)
            ->get();

        return $this->create((string) $rma->tenant_id, [
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'rma_id' => $rma->id,
            'reason_code' => $reasonCode,
            'notes' => $rma->rma_number,
            'lines' => $lines->map(static fn (RmaLine $line): array => [
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity_inspected,
                'rma_line_id' => $line->id,
                'serial_number' => $line->serial_number,
                'lot_number' => $line->lot_number,
            ])->values()->all(),
        ], $userId);
    }

    /**
     * Only a draft changes its lines and its supplier; the transport details change until the return is closed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierReturn $return, array $data): SupplierReturn
    {
        return DB::transaction(function () use ($return, $data): SupplierReturn {
            $locked = $this->lock($return);
            $structural = array_intersect_key($data, array_flip(['supplier_id', 'lines', 'warehouse_id', 'credit_expected', 'currency']));

            if ($structural !== [] && $locked->status !== SupplierReturn::Draft) {
                throw ReturnsError::on('status', 'rtv.locked');
            }

            if (in_array($locked->status, [SupplierReturn::Closed, SupplierReturn::Cancelled], true)) {
                throw ReturnsError::on('status', 'rtv.final');
            }

            if (isset($data['supplier_id'])) {
                $this->assertSupplier((string) $locked->tenant_id, (string) $data['supplier_id']);
            }

            if (! empty($data['reason_code'])) {
                $this->settings->assertActiveReason((string) $locked->tenant_id, (string) $data['reason_code']);
            }

            $locked->update(array_intersect_key($data, array_flip(['supplier_id', 'warehouse_id', 'reason_code', 'carrier', 'tracking_number', 'currency', 'credit_expected', 'notes'])));

            if (isset($data['lines'])) {
                $lines = $this->resolveLines((string) $locked->tenant_id, $data['lines'], $locked->id);
                $locked->lines()->forceDelete();
                $this->saveLines($locked, $lines);

                if (! array_key_exists('credit_expected', $data)) {
                    $locked->update(['credit_expected' => $this->linesTotal($lines)]);
                }
            }

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * Ships a draft return: the lines leave the warehouse through the stock service.
     *
     * @param  array<string, mixed>  $data
     */
    public function ship(SupplierReturn $return, array $data): SupplierReturn
    {
        return DB::transaction(function () use ($return, $data): SupplierReturn {
            $locked = $this->lock($return);
            $this->assertMove($locked, SupplierReturn::Shipped);

            if ($locked->lines()->count() === 0) {
                throw ReturnsError::on('lines', 'rtv.no_lines');
            }

            $warehouse = $this->stock->warehouse((string) $locked->tenant_id, $data['warehouse_id'] ?? $locked->warehouse_id);

            foreach ($locked->lines as $line) {
                $movementId = $this->stock->issue((string) $line->product_id, (string) $line->quantity, $warehouse, $locked, __('returns_errors.rtv.stock_note', ['number' => $locked->return_number]));
                $line->update(['stock_movement_id' => $movementId]);
            }

            $locked->update([
                'status' => SupplierReturn::Shipped,
                'warehouse_id' => $warehouse->id,
                'carrier' => $data['carrier'] ?? $locked->carrier,
                'tracking_number' => $data['tracking_number'] ?? $locked->tracking_number,
                'shipped_at' => now(),
            ]);
            $this->ledger->record('returns.supplier_return_shipped', $locked, ['status' => $locked->status]);

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * Records the credit the supplier granted.
     */
    public function credit(SupplierReturn $return, string $creditReceived): SupplierReturn
    {
        return DB::transaction(function () use ($return, $creditReceived): SupplierReturn {
            $locked = $this->lock($return);
            $this->assertMove($locked, SupplierReturn::Credited);
            $locked->update(['status' => SupplierReturn::Credited, 'credit_received' => $creditReceived, 'credited_at' => now()]);
            $this->ledger->record('returns.supplier_return_credited', $locked, ['credit_received' => $creditReceived]);

            return $locked->refresh()->load('lines');
        });
    }

    public function close(SupplierReturn $return): SupplierReturn
    {
        return DB::transaction(function () use ($return): SupplierReturn {
            $locked = $this->lock($return);
            $this->assertMove($locked, SupplierReturn::Closed);
            $locked->update(['status' => SupplierReturn::Closed, 'closed_at' => now()]);
            $this->ledger->record('returns.supplier_return_closed', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    public function cancel(SupplierReturn $return): SupplierReturn
    {
        return DB::transaction(function () use ($return): SupplierReturn {
            $locked = $this->lock($return);
            $this->assertMove($locked, SupplierReturn::Cancelled);
            $locked->update(['status' => SupplierReturn::Cancelled, 'cancelled_at' => now()]);
            $this->ledger->record('returns.supplier_return_cancelled', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    private function lock(SupplierReturn $return): SupplierReturn
    {
        return SupplierReturn::query()->with('lines')->whereKey($return->id)->lockForUpdate()->firstOrFail();
    }

    private function assertMove(SupplierReturn $return, string $target): void
    {
        if (! $return->canMoveTo($target)) {
            throw ReturnsError::on('status', 'transition', ['from' => $return->status, 'to' => $target]);
        }
    }

    private function assertSupplier(string $tenantId, string $supplierId): void
    {
        if (! Supplier::query()->where('tenant_id', $tenantId)->whereKey($supplierId)->exists()) {
            throw ReturnsError::on('supplier_id', 'rtv.supplier_unknown');
        }
    }

    /**
     * Checks the lines and the quantities against the goods receipt lines they name.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function resolveLines(string $tenantId, array $lines, ?string $exceptReturnId): array
    {
        $resolved = [];
        $requested = [];

        foreach ($lines as $index => $line) {
            $product = Product::query()->where('tenant_id', $tenantId)->whereKey($line['product_id'])->first();

            if ($product === null) {
                throw ReturnsError::on("lines.{$index}.product_id", 'rtv.product_unknown');
            }

            $quantity = Decimal::round($line['quantity'], 4);

            if (! Decimal::isPositive($quantity)) {
                throw ReturnsError::on("lines.{$index}.quantity", 'quantity_positive');
            }

            $receiptItemId = $line['goods_receipt_item_id'] ?? null;

            if ($receiptItemId !== null) {
                $receiptItem = GoodsReceiptItem::query()
                    ->whereKey($receiptItemId)
                    ->whereHas('goodsReceipt', static fn ($query) => $query->where('tenant_id', $tenantId))
                    ->first();

                if ($receiptItem === null || (string) $receiptItem->product_id !== (string) $product->id) {
                    throw ReturnsError::on("lines.{$index}.goods_receipt_item_id", 'rtv.receipt_line_mismatch');
                }

                $requested[$receiptItemId] = Decimal::add($requested[$receiptItemId] ?? '0', $quantity);
                $available = Decimal::sub($receiptItem->quantity, $this->returnedToSupplier((string) $receiptItemId, $exceptReturnId));

                if (Decimal::compare($requested[$receiptItemId], $available) > 0) {
                    throw ReturnsError::on("lines.{$index}.quantity", 'rtv.quantity_exceeds', ['available' => Decimal::round($available, 4), 'line' => (string) ($line['description'] ?? $product->name)]);
                }
            }

            $resolved[] = [
                'product_id' => (string) $product->id,
                'description' => $line['description'] ?? $product->name,
                'quantity' => $quantity,
                'unit_cost' => Decimal::round($line['unit_cost'] ?? $product->cost_price, 4),
                'goods_receipt_item_id' => $receiptItemId,
                'rma_line_id' => $line['rma_line_id'] ?? null,
                'serial_number' => $line['serial_number'] ?? null,
                'lot_number' => $line['lot_number'] ?? null,
            ];
        }

        return $resolved;
    }

    private function returnedToSupplier(string $receiptItemId, ?string $exceptReturnId): string
    {
        $total = SupplierReturnLine::query()
            ->where('goods_receipt_item_id', $receiptItemId)
            ->whereHas('supplierReturn', static fn ($query) => $query
                ->where('status', '!=', SupplierReturn::Cancelled)
                ->when($exceptReturnId !== null, static fn ($inner) => $inner->where('id', '!=', $exceptReturnId)))
            ->sum('quantity');

        return Decimal::of($total);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function saveLines(SupplierReturn $return, array $lines): void
    {
        foreach ($lines as $line) {
            SupplierReturnLine::create(['tenant_id' => $return->tenant_id, 'supplier_return_id' => $return->id, ...$line]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function linesTotal(array $lines): string
    {
        $total = '0';

        foreach ($lines as $line) {
            $total = Decimal::add($total, Decimal::mul($line['quantity'], $line['unit_cost']));
        }

        return Decimal::round($total, 2);
    }
}
