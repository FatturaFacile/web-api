<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\Supplier;

/**
 * Builders of the invoice matching tests (Mod 048): a supplier, an approved purchase order with its lines, goods
 * receipts and supplier invoices with lines, all created inside the context of the given company.
 */
final class InvoiceMatchingFixtures
{
    public static function supplier(Tenant $tenant): Supplier
    {
        return self::in($tenant, static fn (): Supplier => Supplier::factory()->approved()->create(['tenant_id' => $tenant->id]));
    }

    /**
     * @param  list<array{0: string, 1: int|float|string, 2: int|float|string}|array{description: string, quantity: int|float|string, price: int|float|string, receipt_required?: bool}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    public static function order(Tenant $tenant, Supplier $supplier, array $lines, array $attributes = []): PurchaseOrder
    {
        return self::in($tenant, static function () use ($tenant, $supplier, $lines, $attributes): PurchaseOrder {
            $net = '0.00';

            foreach ($lines as $line) {
                [$quantity, $price] = [$line['quantity'] ?? $line[1], $line['price'] ?? $line[2]];
                $net = bcadd($net, bcmul((string) $quantity, (string) $price, 2), 2);
            }

            $order = PurchaseOrder::factory()->approved()->create($attributes + [
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'net_amount' => $net,
                'total_net' => $net,
                'tax_amount' => '0.00',
                'total_tax' => '0.00',
                'total_amount' => $net,
                'total_gross' => $net,
            ]);

            foreach (array_values($lines) as $index => $line) {
                PurchaseOrderLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'purchase_order_id' => $order->id,
                    'line_number' => $index + 1,
                    'description' => $line['description'] ?? $line[0],
                    'quantity_ordered' => $line['quantity'] ?? $line[1],
                    'quantity_cancelled' => 0,
                    'unit_price' => $line['price'] ?? $line[2],
                    'tax_rate' => 0,
                    'receipt_required' => $line['receipt_required'] ?? true,
                ]);
            }

            return $order->refresh();
        });
    }

    /**
     * Books a goods receipt of the order: quantity accepted per line number.
     *
     * @param  array<int, int|float|string>  $accepted
     * @param  array<int, int|float|string>  $returned
     */
    public static function receive(Tenant $tenant, PurchaseOrder $order, array $accepted, string $userId, array $returned = []): PurchaseReceipt
    {
        return self::in($tenant, static function () use ($tenant, $order, $accepted, $userId, $returned): PurchaseReceipt {
            $receipt = PurchaseReceipt::factory()->create([
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $order->id,
                'received_by_user_id' => $userId,
            ]);

            foreach ($accepted as $lineNumber => $quantity) {
                $orderLine = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('line_number', $lineNumber)->firstOrFail();

                PurchaseReceiptLine::factory()->create([
                    'tenant_id' => $tenant->id,
                    'purchase_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'quantity_received' => $quantity,
                    'quantity_accepted' => $quantity,
                    'quantity_rejected' => 0,
                    'quantity_returned' => $returned[$lineNumber] ?? 0,
                    'unit_cost' => $orderLine->unit_price,
                ]);
            }

            return $receipt;
        });
    }

    /**
     * A supplier invoice with lines: `[description, quantity, unit price]`. The net total is the sum of the lines.
     *
     * @param  list<array{0: string, 1: int|float|string, 2: int|float|string}>  $lines
     * @param  array<string, mixed>  $attributes
     */
    public static function invoice(Tenant $tenant, Supplier $supplier, array $lines, array $attributes = []): PassiveInvoice
    {
        return self::in($tenant, static function () use ($tenant, $supplier, $lines, $attributes): PassiveInvoice {
            $net = '0.00';

            foreach ($lines as [, $quantity, $price]) {
                $net = bcadd($net, bcmul((string) $quantity, (string) $price, 2), 2);
            }

            $invoice = PassiveInvoice::withoutGlobalScopes()->create($attributes + [
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'invoice_number' => 'FT-'.strtoupper(Str::random(6)),
                'date' => now()->subDays(3)->toDateString(),
                'due_date' => now()->addDays(27)->toDateString(),
                'currency' => 'EUR',
                'total_net' => $net,
                'total_tax' => '0.00',
                'total_amount' => $net,
                'document_type' => 'TD01',
                'payment_status' => 'unpaid',
                'total_paid' => 0,
            ]);

            foreach (array_values($lines) as $index => [$description, $quantity, $price]) {
                PassiveInvoiceLine::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'passive_invoice_id' => $invoice->id,
                    'line_number' => $index + 1,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'line_total' => bcmul((string) $quantity, (string) $price, 2),
                    'tax_rate' => 0,
                ]);
            }

            return $invoice->refresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function in(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runWithTenant($tenant, $callback);
    }
}
