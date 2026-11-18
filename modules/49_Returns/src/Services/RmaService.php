<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\Supplier;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\RmaLine;
use Modules\Returns\Models\WarrantyClaim;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Customer returns (RMA). A return refers to the invoice or the sales order the goods were sold on, line by line: a line
 * can be returned up to what the source line sold less what other returns (and the sales returns of the sales module)
 * already took back. The return is approved, received in a warehouse, inspected line by line (the disposition books the
 * stock movement through the inventory module) and resolved with a refund, a credit note reference, a replacement or a
 * repair. The return window of the settings applies to the date of the source document.
 */
final readonly class RmaService
{
    /**
     * Statuses of the sales returns of the sales module whose quantities count against what can still be returned.
     *
     * @var list<string>
     */
    private const array SalesReturnCountingStatuses = ['requested', 'approved', 'received', 'credited'];

    public function __construct(
        private ReturnsNumbering $numbering,
        private ReturnsSettingsService $settings,
        private ReturnsStockBooker $stock,
        private SupplierReturnService $supplierReturns,
        private ReturnsLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId, bool $mayOverrideWindow): Rma
    {
        return DB::transaction(function () use ($tenantId, $data, $userId, $mayOverrideWindow): Rma {
            [$rma, $lines] = $this->prepare($tenantId, $data, null, $mayOverrideWindow);

            $rma = Rma::create([
                'tenant_id' => $tenantId,
                'rma_number' => $this->numbering->next(Rma::class, 'rma_number', 'RMA', $tenantId),
                'contact_id' => $data['contact_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'status' => Rma::Requested,
                'reason_code' => $data['reason_code'],
                'requested_on' => $data['requested_on'] ?? now()->toDateString(),
                'window_overridden' => $rma['window_overridden'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $this->saveLines($rma, $lines);
            $this->ledger->record('returns.rma_requested', $rma, ['status' => $rma->status]);

            return $rma->load('lines');
        });
    }

    /**
     * A return changes only while it is requested; the customer and the source document are fixed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Rma $rma, array $data, bool $mayOverrideWindow): Rma
    {
        return DB::transaction(function () use ($rma, $data, $mayOverrideWindow): Rma {
            $locked = $this->lock($rma);

            if ($locked->status !== Rma::Requested) {
                throw ReturnsError::on('status', 'rma.locked');
            }

            $merged = [
                'contact_id' => $locked->contact_id,
                'invoice_id' => $locked->invoice_id,
                'sales_order_id' => $locked->sales_order_id,
                'requested_on' => $data['requested_on'] ?? $locked->requested_on->toDateString(),
                'reason_code' => $data['reason_code'] ?? $locked->reason_code,
                'lines' => $data['lines'] ?? $locked->lines->map(static fn (RmaLine $line): array => [
                    'invoice_item_id' => $line->invoice_item_id,
                    'sales_order_item_id' => $line->sales_order_item_id,
                    'quantity' => $line->quantity,
                    'serial_number' => $line->serial_number,
                    'lot_number' => $line->lot_number,
                    'reason_code' => $line->reason_code,
                ])->all(),
                'override_window' => $data['override_window'] ?? $locked->window_overridden,
            ];
            [$checked, $lines] = $this->prepare((string) $locked->tenant_id, $merged, $locked->id, $mayOverrideWindow);

            $locked->update([
                'reason_code' => $merged['reason_code'],
                'requested_on' => $merged['requested_on'],
                'window_overridden' => $checked['window_overridden'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ]);

            if (isset($data['lines'])) {
                $locked->lines()->forceDelete();
                $this->saveLines($locked, $lines);
            }

            return $locked->refresh()->load('lines');
        });
    }

    public function approve(Rma $rma, ?string $userId, ?string $warehouseId): Rma
    {
        return DB::transaction(function () use ($rma, $userId, $warehouseId): Rma {
            $locked = $this->lock($rma);
            $this->assertMove($locked, Rma::Approved);
            $warehouse = $warehouseId !== null ? $this->stock->warehouse((string) $locked->tenant_id, $warehouseId) : null;
            $locked->update(['status' => Rma::Approved, 'approved_at' => now(), 'approved_by' => $userId, 'warehouse_id' => $warehouse->id ?? $locked->warehouse_id]);
            $this->ledger->record('returns.rma_approved', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    public function reject(Rma $rma, string $reason): Rma
    {
        return DB::transaction(function () use ($rma, $reason): Rma {
            $locked = $this->lock($rma);
            $this->assertMove($locked, Rma::Rejected);
            $locked->update(['status' => Rma::Rejected, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->ledger->record('returns.rma_rejected', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * The goods arrive: every line is received in full unless the request gives the quantity that actually came.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(Rma $rma, array $data): Rma
    {
        return DB::transaction(function () use ($rma, $data): Rma {
            $locked = $this->lock($rma);
            $this->assertMove($locked, Rma::Received);
            $byId = collect($data['lines'] ?? [])->keyBy(static fn (array $line): string => (string) $line['id']);

            foreach ($byId->keys() as $id) {
                if (! $locked->lines->contains('id', $id)) {
                    throw ReturnsError::on('lines', 'rma.line_not_of_return');
                }
            }

            $any = false;

            foreach ($locked->lines as $line) {
                $received = Decimal::round($byId->has((string) $line->id) ? $byId->get((string) $line->id)['quantity_received'] : $line->quantity, 4);

                if (Decimal::compare($received, '0') < 0 || Decimal::compare($received, $line->quantity) > 0) {
                    throw ReturnsError::on('lines', 'rma.received_exceeds', ['line' => $line->description]);
                }

                $any = $any || Decimal::isPositive($received);
                $line->update(['quantity_received' => $received]);
            }

            if (! $any) {
                throw ReturnsError::on('lines', 'rma.nothing_received');
            }

            $warehouse = $this->stock->warehouse((string) $locked->tenant_id, $data['warehouse_id'] ?? $locked->warehouse_id);
            $locked->update(['status' => Rma::Received, 'received_at' => now(), 'warehouse_id' => $warehouse->id]);
            $this->ledger->record('returns.rma_received', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * Decides what happens to every received line. Restock and return to supplier put the goods into the warehouse
     * through the inventory stock service (a return to supplier also drafts the supplier return); scrap and refurbish
     * move no stock: scrapped goods never become stock and refurbished goods come back into stock when the repair
     * ends, by a stock adjustment of the inventory module.
     *
     * @param  array<string, mixed>  $data
     */
    public function inspect(Rma $rma, array $data, ?string $userId): Rma
    {
        return DB::transaction(function () use ($rma, $data, $userId): Rma {
            $locked = $this->lock($rma);
            $this->assertMove($locked, Rma::Inspected);
            $byId = collect($data['lines'])->keyBy(static fn (array $line): string => (string) $line['id']);

            foreach ($byId->keys() as $id) {
                if (! $locked->lines->contains('id', $id)) {
                    throw ReturnsError::on('lines', 'rma.line_not_of_return');
                }
            }

            $supplierRoutes = [];

            foreach ($locked->lines as $line) {
                if (! Decimal::isPositive($line->quantity_received)) {
                    continue;
                }

                $input = $byId->get((string) $line->id);

                if ($input === null) {
                    throw ReturnsError::on('lines', 'rma.disposition_required', ['line' => $line->description]);
                }

                $inspected = Decimal::round($input['quantity_inspected'] ?? $line->quantity_received, 4);

                if (! Decimal::isPositive($inspected) || Decimal::compare($inspected, $line->quantity_received) > 0) {
                    throw ReturnsError::on('lines', 'rma.inspected_exceeds', ['line' => $line->description]);
                }

                $disposition = (string) $input['disposition'];
                $supplierId = $input['supplier_id'] ?? null;

                if ($disposition === RmaLine::ReturnToSupplier) {
                    if ($supplierId === null || ! Supplier::query()->where('tenant_id', $locked->tenant_id)->whereKey($supplierId)->exists()) {
                        throw ReturnsError::on('lines', 'rma.supplier_required', ['line' => $line->description]);
                    }

                    $supplierRoutes[(string) $supplierId] = true;
                }

                $movementId = null;

                if (in_array($disposition, [RmaLine::Restock, RmaLine::ReturnToSupplier], true)) {
                    $warehouse = $this->stock->warehouse((string) $locked->tenant_id, $input['warehouse_id'] ?? $locked->warehouse_id);
                    $movementId = $this->stock->receive((string) $line->product_id, $inspected, $warehouse, $locked, __('returns_errors.rma.stock_note', ['number' => $locked->rma_number]));
                    $line->disposition_warehouse_id = $warehouse->id;
                }

                $line->update([
                    'quantity_inspected' => $inspected,
                    'disposition' => $disposition,
                    'supplier_id' => $disposition === RmaLine::ReturnToSupplier ? $supplierId : null,
                    'inspection_notes' => $input['notes'] ?? null,
                    'stock_movement_id' => $movementId,
                    'disposition_warehouse_id' => $line->disposition_warehouse_id,
                ]);
            }

            $locked->update(['status' => Rma::Inspected, 'inspected_at' => now()]);
            $locked->load('lines');

            foreach (array_keys($supplierRoutes) as $supplierId) {
                $warehouseId = (string) ($locked->lines->firstWhere('supplier_id', $supplierId)->disposition_warehouse_id ?? $locked->warehouse_id);
                $this->supplierReturns->draftFromRma($locked, $supplierId, $warehouseId, $locked->reason_code, $userId);
            }

            $this->ledger->record('returns.rma_inspected', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * Closes the case with a refund, a credit note (its draft reference), a replacement (the goods leave the
     * warehouse through the stock service) or a repair.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolve(Rma $rma, array $data): Rma
    {
        return DB::transaction(function () use ($rma, $data): Rma {
            $locked = $this->lock($rma);
            $this->assertMove($locked, Rma::Resolved);
            $type = (string) $data['resolution_type'];
            $refund = null;

            if (in_array($type, [Rma::ResolutionRefund, Rma::ResolutionCreditNote], true)) {
                $refund = Decimal::round($data['refund_amount'] ?? $this->inspectedValue($locked), 2);

                if ($type === Rma::ResolutionCreditNote && empty($data['credit_note_reference'])) {
                    throw ReturnsError::on('credit_note_reference', 'rma.credit_note_reference_required');
                }
            }

            if ($type === Rma::ResolutionReplacement) {
                $warehouse = $this->stock->warehouse((string) $locked->tenant_id, $data['warehouse_id'] ?? $locked->warehouse_id);

                foreach ($locked->lines->filter(static fn (RmaLine $line): bool => Decimal::isPositive($line->quantity_inspected)) as $line) {
                    $this->stock->issue((string) $line->product_id, (string) $line->quantity_inspected, $warehouse, $locked, __('returns_errors.rma.replacement_note', ['number' => $locked->rma_number]));
                }
            }

            $locked->update([
                'status' => Rma::Resolved,
                'resolved_at' => now(),
                'resolution_type' => $type,
                'refund_amount' => $refund,
                'credit_note_reference' => $type === Rma::ResolutionCreditNote ? $data['credit_note_reference'] : null,
                'resolution_notes' => $data['notes'] ?? null,
            ]);
            WarrantyClaim::query()->where('rma_id', $locked->id)->where('status', WarrantyClaim::Accepted)->update(['status' => WarrantyClaim::Resolved, 'resolved_at' => now()]);
            $this->ledger->record('returns.rma_resolved', $locked, ['resolution_type' => $type, 'refund_amount' => $refund]);

            return $locked->refresh()->load('lines');
        });
    }

    public function close(Rma $rma): Rma
    {
        return DB::transaction(function () use ($rma): Rma {
            $locked = $this->lock($rma);
            $this->assertMove($locked, Rma::Closed);
            $locked->update(['status' => Rma::Closed, 'closed_at' => now()]);
            $this->ledger->record('returns.rma_closed', $locked);

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * The documents a customer can return goods against (the latest invoices and the confirmed sales orders) with the
     * quantity of every line that can still be returned and whether the return window is still open.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(string $tenantId, string $contactId): array
    {
        $windowDays = $this->settings->windowDays($tenantId);
        $today = Carbon::parse(now()->toDateString());
        $sources = [];

        $invoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->whereNotIn('invoice_type', ['credit_note', 'proforma'])
            ->orderByDesc('date')
            ->limit(50)
            ->get();
        $items = InvoiceItem::query()->whereIn('invoice_id', $invoices->pluck('id'))->whereNotNull('product_id')->get()->groupBy('invoice_id');

        foreach ($invoices as $invoice) {
            $sources[] = $this->sourceRow('invoice', (string) $invoice->id, (string) $invoice->invoice_number, Carbon::parse((string) $invoice->date), $windowDays, $today, 'invoice_item_id', $items->get($invoice->id, collect()));
        }

        $orders = SalesOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('date')
            ->limit(50)
            ->get();
        $orderItems = SalesOrderItem::query()->whereIn('sales_order_id', $orders->pluck('id'))->whereNotNull('product_id')->get()->groupBy('sales_order_id');

        foreach ($orders as $order) {
            $sources[] = $this->sourceRow('sales_order', (string) $order->id, (string) $order->order_number, Carbon::parse((string) $order->date), $windowDays, $today, 'sales_order_item_id', $orderItems->get($order->id, collect()));
        }

        return $sources;
    }

    /**
     * @param  Collection<int, InvoiceItem|SalesOrderItem>  $items
     * @return array<string, mixed>
     */
    private function sourceRow(string $type, string $id, string $number, Carbon $date, int $windowDays, Carbon $today, string $column, Collection $items): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'number' => $number,
            'date' => $date->toDateString(),
            'within_window' => ! $date->copy()->addDays($windowDays)->lt($today),
            'lines' => $items->map(function (InvoiceItem|SalesOrderItem $item) use ($column): array {
                $returned = $this->alreadyReturned($column, (string) $item->id, null);

                return [
                    'source_item_id' => (string) $item->id,
                    'product_id' => (string) $item->product_id,
                    'description' => (string) $item->description,
                    'quantity_sold' => Decimal::round($item->quantity, 4),
                    'quantity_returned' => Decimal::round($returned, 4),
                    'quantity_available' => Decimal::round(max('0', Decimal::sub($item->quantity, $returned)), 4),
                    'unit_price' => $this->netUnitPrice($item),
                ];
            })->values()->all(),
        ];
    }

    private function lock(Rma $rma): Rma
    {
        return Rma::query()->with('lines')->whereKey($rma->id)->lockForUpdate()->firstOrFail();
    }

    private function assertMove(Rma $rma, string $target): void
    {
        if (! $rma->canMoveTo($target)) {
            throw ReturnsError::on('status', 'transition', ['from' => $rma->status, 'to' => $target]);
        }
    }

    /**
     * Value of the inspected goods at the price they were sold at.
     */
    private function inspectedValue(Rma $rma): string
    {
        $total = '0';

        foreach ($rma->lines as $line) {
            $total = Decimal::add($total, Decimal::mul($line->quantity_inspected, $line->unit_price));
        }

        return Decimal::round($total, 2);
    }

    /**
     * Validates the customer, the source document, the return window, the reason and the lines against what the source
     * lines sold less what was already returned.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array{window_overridden: bool}, 1: list<array<string, mixed>>}
     */
    private function prepare(string $tenantId, array $data, ?string $exceptRmaId, bool $mayOverrideWindow): array
    {
        $contactId = (string) $data['contact_id'];

        if (! Contact::query()->where('tenant_id', $tenantId)->whereKey($contactId)->exists()) {
            throw ReturnsError::on('contact_id', 'rma.customer_unknown');
        }

        $this->settings->assertActiveReason($tenantId, (string) $data['reason_code']);
        $invoice = ! empty($data['invoice_id']) ? $this->invoiceOf($tenantId, (string) $data['invoice_id'], $contactId) : null;
        $order = ! empty($data['sales_order_id']) ? $this->orderOf($tenantId, (string) $data['sales_order_id'], $contactId) : null;

        if ($invoice === null && $order === null) {
            throw ReturnsError::on('invoice_id', 'rma.source_required');
        }

        $soldOn = Carbon::parse((string) ($invoice ?? $order)->date);
        $requestedOn = Carbon::parse((string) ($data['requested_on'] ?? now()->toDateString()));
        $windowDays = $this->settings->windowDays($tenantId);
        $outside = $soldOn->copy()->addDays($windowDays)->lt($requestedOn);

        if ($outside) {
            if (empty($data['override_window'])) {
                throw ReturnsError::on('requested_on', 'rma.window_expired', ['days' => $windowDays]);
            }

            if (! $mayOverrideWindow) {
                throw ReturnsError::on('override_window', 'rma.override_not_allowed');
            }
        }

        $lines = [];
        $requested = [];

        foreach ($data['lines'] as $index => $line) {
            $quantity = Decimal::round($line['quantity'], 4);

            if (! Decimal::isPositive($quantity)) {
                throw ReturnsError::on("lines.{$index}.quantity", 'quantity_positive');
            }

            if (! empty($line['invoice_item_id'])) {
                $source = $invoice !== null ? InvoiceItem::query()->where('invoice_id', $invoice->id)->whereKey($line['invoice_item_id'])->first() : null;
                $column = 'invoice_item_id';
                $itemId = (string) $line['invoice_item_id'];
            } elseif (! empty($line['sales_order_item_id'])) {
                $source = $order !== null ? SalesOrderItem::query()->where('sales_order_id', $order->id)->whereKey($line['sales_order_item_id'])->first() : null;
                $column = 'sales_order_item_id';
                $itemId = (string) $line['sales_order_item_id'];
            } else {
                throw ReturnsError::on("lines.{$index}.invoice_item_id", 'rma.line_source_required');
            }

            if ($source === null || $source->product_id === null) {
                throw ReturnsError::on("lines.{$index}.{$column}", 'rma.line_not_of_source');
            }

            $requested[$itemId] = Decimal::add($requested[$itemId] ?? '0', $quantity);
            $available = Decimal::sub($source->quantity, $this->alreadyReturned($column, $itemId, $exceptRmaId));

            if (Decimal::compare($requested[$itemId], $available) > 0) {
                throw ReturnsError::on("lines.{$index}.quantity", 'rma.quantity_exceeds', [
                    'available' => Decimal::round(max('0', $available), 4),
                    'line' => (string) $source->description,
                ]);
            }

            if (! empty($line['reason_code'])) {
                $this->settings->assertActiveReason($tenantId, (string) $line['reason_code'], "lines.{$index}.reason_code");
            }

            $product = Product::withTrashed()->where('tenant_id', $tenantId)->whereKey($source->product_id)->first();

            if ($product === null) {
                throw ReturnsError::on("lines.{$index}.{$column}", 'rma.line_not_of_source');
            }

            $lines[] = [
                'invoice_item_id' => $column === 'invoice_item_id' ? $itemId : null,
                'sales_order_item_id' => $column === 'sales_order_item_id' ? $itemId : null,
                'product_id' => (string) $product->id,
                'description' => (string) $source->description,
                'quantity' => $quantity,
                'unit_price' => $this->netUnitPrice($source),
                'serial_number' => $line['serial_number'] ?? null,
                'lot_number' => $line['lot_number'] ?? null,
                'reason_code' => $line['reason_code'] ?? null,
            ];
        }

        return [['window_overridden' => $outside], $lines];
    }

    private function invoiceOf(string $tenantId, string $invoiceId, string $contactId): Invoice
    {
        $invoice = Invoice::query()->where('tenant_id', $tenantId)->whereKey($invoiceId)->first();

        if ($invoice === null || (string) $invoice->contact_id !== $contactId || in_array($invoice->invoice_type, ['credit_note', 'proforma'], true)) {
            throw ReturnsError::on('invoice_id', 'rma.invoice_not_returnable');
        }

        return $invoice;
    }

    private function orderOf(string $tenantId, string $orderId, string $contactId): SalesOrder
    {
        $order = SalesOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->first();

        if ($order === null || (string) $order->contact_id !== $contactId) {
            throw ReturnsError::on('sales_order_id', 'rma.order_not_returnable');
        }

        return $order;
    }

    /**
     * Net price of one unit as the customer paid it (the line discount applied).
     */
    private function netUnitPrice(InvoiceItem|SalesOrderItem $source): string
    {
        $factor = Decimal::sub('1', Decimal::div($source->discount_percentage, '100'));

        return Decimal::round(Decimal::mul($source->unit_price, $factor), 4);
    }

    /**
     * What other returns already took back of a source line: the quantity requested while a return is open, the
     * quantity received once it arrived, plus the sales returns of the sales module on the same invoice line.
     */
    private function alreadyReturned(string $column, string $itemId, ?string $exceptRmaId): string
    {
        $total = '0';
        $rows = DB::table('returns_rma_lines')
            ->join('returns_rmas', 'returns_rmas.id', '=', 'returns_rma_lines.rma_id')
            ->where("returns_rma_lines.{$column}", $itemId)
            ->whereNull('returns_rma_lines.deleted_at')
            ->whereNull('returns_rmas.deleted_at')
            ->whereIn('returns_rmas.status', Rma::CountingStatuses)
            ->when($exceptRmaId !== null, static fn ($query) => $query->where('returns_rmas.id', '!=', $exceptRmaId))
            ->get(['returns_rma_lines.quantity', 'returns_rma_lines.quantity_received', 'returns_rmas.status']);

        foreach ($rows as $row) {
            $counts = in_array($row->status, [Rma::Requested, Rma::Approved], true) ? $row->quantity : $row->quantity_received;
            $total = Decimal::add($total, $counts);
        }

        if ($column === 'invoice_item_id') {
            $total = Decimal::add($total, DB::table('sales_return_items')
                ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
                ->where('sales_return_items.invoice_item_id', $itemId)
                ->whereNull('sales_returns.deleted_at')
                ->whereIn('sales_returns.status', self::SalesReturnCountingStatuses)
                ->sum('sales_return_items.quantity'));
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function saveLines(Rma $rma, array $lines): void
    {
        foreach ($lines as $line) {
            RmaLine::create(['tenant_id' => $rma->tenant_id, 'rma_id' => $rma->id, ...$line]);
        }
    }
}
