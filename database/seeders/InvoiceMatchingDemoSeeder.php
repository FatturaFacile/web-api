<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Procurement\Enums\MatchTrigger;
use Modules\Procurement\Enums\ToleranceScope;
use Modules\Procurement\Models\InvoiceMatchToleranceRule;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\InvoiceMatchingService;
use Modules\Procurement\Services\InvoiceMatchResolutionService;

/**
 * Demo data of the invoice matching (Mod 048): the tolerance rules of the company and one purchase order / goods
 * receipt / supplier invoice scenario for every outcome: matched, within tolerance, 2-way service match, blocked for
 * discrepancy, blocked with a credit note requested, and released by a supervisor override. Runs after the procurement
 * demo data (it uses its first supplier) and can be run again: scenarios that exist are left as they are.
 */
final class InvoiceMatchingDemoSeeder extends Seeder
{
    private const string SupplierCode = 'SUP-0001';

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->runWithTenant($tenant, fn () => $this->seedTenant($tenant));
        }
    }

    private function seedTenant(Tenant $tenant): void
    {
        $supplier = Supplier::query()->where('tenant_id', $tenant->id)->where('supplier_code', self::SupplierCode)->first();
        $user = User::query()->first();

        if ($supplier === null || $user === null) {
            return;
        }

        $this->seedRules($tenant, $supplier);

        $fan = ['Industrial Fan Unit', 10, '100.00'];
        $kit = ['Mounting Kit', 5, '20.00'];

        $this->scenario($tenant, $supplier, $user, 'MATCHED', [$fan, $kit], [1 => 10, 2 => 5], [$fan, $kit]);
        $this->scenario($tenant, $supplier, $user, 'TOLERANCE', [$fan, $kit], [1 => 10, 2 => 5], [['Industrial Fan Unit', 10, '101.50'], $kit]);
        $this->scenario($tenant, $supplier, $user, 'SERVICE', [['Consulting hours', 40, '150.00', false]], [], [['Consulting hours', 40, '150.00']]);
        $this->scenario($tenant, $supplier, $user, 'BLOCKED', [$fan, $kit], [1 => 10, 2 => 5], [['Industrial Fan Unit', 10, '112.00'], $kit]);
        $this->scenario($tenant, $supplier, $user, 'OVERBILLED', [$fan, $kit], [1 => 6, 2 => 5], [$fan, $kit]);
        $this->scenario($tenant, $supplier, $user, 'CREDITNOTE', [$fan, $kit], [1 => 10, 2 => 5], [['Industrial Fan Unit', 10, '125.00'], $kit], function ($run) use ($user): void {
            app(InvoiceMatchResolutionService::class)->requestCreditNote($run, (string) $user->id, 'Unit price above the contract: credit note for the difference requested', '250.00');
        });
        $this->scenario($tenant, $supplier, $user, 'OVERRIDE', [$fan, $kit], [1 => 10, 2 => 5], [['Industrial Fan Unit', 10, '118.00'], $kit], function ($run) use ($user): void {
            app(InvoiceMatchResolutionService::class)->override($run, (string) $user->id, 'Price increase approved by the CFO pending the contract addendum');
        });
    }

    private function seedRules(Tenant $tenant, Supplier $supplier): void
    {
        $rules = [
            ['name' => 'Default tolerance', 'scope' => ToleranceScope::Tenant, 'priority' => 100, 'price_tolerance_percent' => 1, 'price_tolerance_amount' => 5, 'quantity_tolerance_percent' => 0, 'quantity_tolerance_amount' => 0],
            ['name' => 'Strategic supplier', 'scope' => ToleranceScope::Supplier, 'supplier_id' => $supplier->id, 'priority' => 50, 'price_tolerance_percent' => 2, 'price_tolerance_amount' => 10, 'quantity_tolerance_percent' => 0, 'quantity_tolerance_amount' => 0],
            ['name' => 'Packaging', 'scope' => ToleranceScope::ItemCategory, 'item_category' => 'packaging', 'priority' => 100, 'price_tolerance_percent' => 3, 'price_tolerance_amount' => 2, 'quantity_tolerance_percent' => 2, 'quantity_tolerance_amount' => 0],
            ['name' => 'Services (2-way)', 'scope' => ToleranceScope::Rule, 'match_type' => '2_way', 'priority' => 10, 'price_tolerance_percent' => 0.5, 'price_tolerance_amount' => 0, 'quantity_tolerance_percent' => 0, 'quantity_tolerance_amount' => 0],
        ];

        foreach ($rules as $rule) {
            InvoiceMatchToleranceRule::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $rule['name']],
                $rule + ['is_active' => true],
            );
        }
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}|array{0: string, 1: int, 2: string, 3: bool}>  $orderLines  description, quantity, unit price, receipt required
     * @param  array<int, int>  $received  quantity accepted per line number
     * @param  list<array{0: string, 1: int, 2: string}>  $invoiceLines
     */
    private function scenario(Tenant $tenant, Supplier $supplier, User $user, string $key, array $orderLines, array $received, array $invoiceLines, ?callable $afterMatching = null): void
    {
        $poNumber = "PO-DEMO-3WM-{$key}";

        if (PurchaseOrder::query()->where('tenant_id', $tenant->id)->where('po_number', $poNumber)->exists()) {
            return;
        }

        $net = array_reduce($orderLines, static fn (string $sum, array $line): string => bcadd($sum, bcmul((string) $line[1], $line[2], 2), 2), '0.00');

        $order = PurchaseOrder::factory()->approved()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'po_number' => $poNumber,
            'order_number' => $poNumber,
            'net_amount' => $net,
            'total_net' => $net,
            'tax_amount' => '0.00',
            'total_tax' => '0.00',
            'total_amount' => $net,
            'total_gross' => $net,
        ]);

        foreach ($orderLines as $index => $line) {
            PurchaseOrderLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'purchase_order_id' => $order->id,
                'line_number' => $index + 1,
                'description' => $line[0],
                'quantity_ordered' => $line[1],
                'quantity_cancelled' => 0,
                'unit_price' => $line[2],
                'tax_rate' => 0,
                'receipt_required' => $line[3] ?? true,
            ]);
        }

        if ($received !== []) {
            $receipt = PurchaseReceipt::factory()->create(['tenant_id' => $tenant->id, 'purchase_order_id' => $order->id, 'received_by_user_id' => $user->id]);

            foreach ($received as $lineNumber => $quantity) {
                $orderLine = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('line_number', $lineNumber)->firstOrFail();
                PurchaseReceiptLine::factory()->create([
                    'tenant_id' => $tenant->id,
                    'purchase_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'quantity_received' => $quantity,
                    'quantity_accepted' => $quantity,
                    'quantity_rejected' => 0,
                    'quantity_returned' => 0,
                    'unit_cost' => $orderLine->unit_price,
                ]);
            }
        }

        $invoiceNet = array_reduce($invoiceLines, static fn (string $sum, array $line): string => bcadd($sum, bcmul((string) $line[1], $line[2], 2), 2), '0.00');

        $invoice = PassiveInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => "DEMO-3WM-{$key}",
            'date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(25)->toDateString(),
            'currency' => 'EUR',
            'total_net' => $invoiceNet,
            'total_tax' => '0.00',
            'total_amount' => $invoiceNet,
            'document_type' => 'TD01',
            'payment_status' => 'unpaid',
            'total_paid' => 0,
            'xml_payload' => '<p:FatturaElettronica><DatiOrdineAcquisto><IdDocumento>'.$poNumber.'</IdDocumento></DatiOrdineAcquisto></p:FatturaElettronica>',
        ]);

        foreach ($invoiceLines as $index => $line) {
            PassiveInvoiceLine::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'passive_invoice_id' => $invoice->id,
                'line_number' => $index + 1,
                'description' => $line[0],
                'quantity' => $line[1],
                'unit_price' => $line[2],
                'line_total' => bcmul((string) $line[1], $line[2], 2),
                'tax_rate' => 0,
            ]);
        }

        $run = app(InvoiceMatchingService::class)->run((string) $invoice->id, null, MatchTrigger::Auto, (string) $user->id);

        if ($afterMatching !== null) {
            $afterMatching($run);
        }
    }
}
