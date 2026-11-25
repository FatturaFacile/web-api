<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Returns\Models\AdvanceReplacement;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\CustomerReturnLine;
use Modules\Returns\Models\RefurbishedItem;
use Modules\Returns\Models\ReturnGradingInspection;
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Models\ReturnsSetting;

/**
 * Idempotent demo & reference seeder for Mod 232 (Customer Returns, Advance Replacements,
 * Refurbished / B-Stock Grading, and Credit Note Art. 26 DPR 633/72 control).
 */
final class CustomerReturnsReverseLogisticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant): void {
            $tenantId = (string) $tenant->id;

            $outlet = Warehouse::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => 'OUTLET'],
                ['name' => 'Outlet & Refurbished Warehouse', 'is_primary' => false]
            );

            ReturnsSetting::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'return_window_days' => 30,
                    'warranty_window_days' => 730,
                    'default_restocking_fee_percent' => '10.00',
                    'advance_replacement_sla_days' => 15,
                    'outlet_devaluation_grade_b_percent' => '20.00',
                    'outlet_devaluation_grade_c_percent' => '35.00',
                    'outlet_warehouse_id' => $outlet->id,
                ]
            );

            foreach (ReturnReasonCode::Defaults as $code => [$label, $isDefect]) {
                ReturnReasonCode::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $code],
                    ['label' => $label, 'is_defect' => $isDefect, 'is_active' => true]
                );
            }

            $contact = Contact::query()->where('tenant_id', $tenantId)->first();
            $product = Product::query()->where('tenant_id', $tenantId)->first();
            if ($contact === null || $product === null) {
                return;
            }

            $recentInvoice = Invoice::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'invoice_number' => 'INV-RL-2026-001'],
                [
                    'contact_id' => $contact->id,
                    'invoice_type' => 'standard',
                    'date' => now()->subDays(10)->toDateString(),
                    'total_net' => 250.00,
                    'total_tax' => 55.00,
                    'total_gross' => 305.00,
                ]
            );
            $recentItem = InvoiceItem::query()->firstOrCreate(
                ['invoice_id' => $recentInvoice->id, 'product_id' => $product->id],
                [
                    'description' => $product->name,
                    'quantity' => 2,
                    'unit_price' => 125.00,
                    'tax_rate' => 22.00,
                    'line_total' => 250.00,
                ]
            );

            $oldInvoice = Invoice::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'invoice_number' => 'INV-RL-OLD-001'],
                [
                    'contact_id' => $contact->id,
                    'invoice_type' => 'standard',
                    'date' => now()->subDays(410)->toDateString(),
                    'total_net' => 125.00,
                    'total_tax' => 27.50,
                    'total_gross' => 152.50,
                ]
            );
            $oldItem = InvoiceItem::query()->firstOrCreate(
                ['invoice_id' => $oldInvoice->id, 'product_id' => $product->id],
                [
                    'description' => $product->name,
                    'quantity' => 1,
                    'unit_price' => 125.00,
                    'tax_rate' => 22.00,
                    'line_total' => 125.00,
                ]
            );

            $returnRecent = CustomerReturn::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'rma_number' => 'CRMA-2026-0001'],
                [
                    'rma_barcode' => 'RMA-BC-CRMA20260001-DEMO',
                    'return_tracking_number' => 'TRK-CRMA-2026-0001',
                    'carrier' => 'DHL Express Reverse',
                    'contact_id' => $contact->id,
                    'invoice_id' => $recentInvoice->id,
                    'reason_code' => 'doa',
                    'channel' => CustomerReturn::ChannelPortalB2B,
                    'status' => CustomerReturn::StatusReceived,
                    'requested_on' => now()->toDateString(),
                    'within_return_window' => true,
                    'within_warranty_window' => true,
                    'days_since_purchase' => 10,
                    'restocking_fee_applicable' => false,
                    'restocking_fee_percent' => '0.00',
                    'restocking_fee_amount' => '0.00',
                    'gross_return_amount' => '125.00',
                    'net_refundable_amount' => '125.00',
                    'photo_urls' => ['https://cdn.fatturafacile.local/returns/demo-doa-1.jpg'],
                ]
            );

            CustomerReturnLine::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'customer_return_id' => $returnRecent->id, 'product_id' => $product->id],
                [
                    'invoice_item_id' => $recentItem->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'quantity_received' => 1,
                    'unit_price' => 125.00,
                    'tax_rate' => 22.00,
                ]
            );

            $returnOld = CustomerReturn::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'rma_number' => 'CRMA-2026-0002'],
                [
                    'rma_barcode' => 'RMA-BC-CRMA20260002-DEMO',
                    'return_tracking_number' => 'TRK-CRMA-2026-0002',
                    'carrier' => 'UPS Reverse',
                    'contact_id' => $contact->id,
                    'invoice_id' => $oldInvoice->id,
                    'reason_code' => 'commercial_return',
                    'channel' => CustomerReturn::ChannelBackoffice,
                    'status' => CustomerReturn::StatusInspected,
                    'requested_on' => now()->toDateString(),
                    'within_return_window' => false,
                    'within_warranty_window' => true,
                    'window_overridden' => true,
                    'days_since_purchase' => 410,
                    'restocking_fee_applicable' => true,
                    'restocking_fee_percent' => '10.00',
                    'restocking_fee_amount' => '12.50',
                    'gross_return_amount' => '125.00',
                    'net_refundable_amount' => '112.50',
                    'photo_urls' => ['https://cdn.fatturafacile.local/returns/demo-old-1.jpg'],
                ]
            );

            CustomerReturnLine::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'customer_return_id' => $returnOld->id, 'product_id' => $product->id],
                [
                    'invoice_item_id' => $oldItem->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'quantity_received' => 1,
                    'quantity_inspected' => 1,
                    'unit_price' => 125.00,
                    'tax_rate' => 22.00,
                ]
            );

            AdvanceReplacement::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'replacement_number' => 'XSHP-2026-0001'],
                [
                    'customer_return_id' => $returnRecent->id,
                    'contact_id' => $contact->id,
                    'original_invoice_id' => $recentInvoice->id,
                    'faulty_product_id' => $product->id,
                    'replacement_product_id' => $product->id,
                    'replacement_unit_type' => AdvanceReplacement::UnitNew,
                    'quantity' => 1,
                    'status' => AdvanceReplacement::StatusPendingDefectiveReturn,
                    'shipped_on' => now()->subDays(5)->toDateString(),
                    'sla_days' => 15,
                    'return_due_on' => now()->addDays(10)->toDateString(),
                    'penalty_amount' => 125.00,
                    'currency' => 'EUR',
                ]
            );

            $refurbished = RefurbishedItem::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'refurbished_sku' => $product->sku.'-BSTOCK-B-DEMO'],
                [
                    'original_product_id' => $product->id,
                    'customer_return_id' => $returnOld->id,
                    'grade' => ReturnGradingInspection::GradeB,
                    'quantity' => 1,
                    'original_cost' => 80.00,
                    'devaluation_percent' => 20.00,
                    'refurbishment_cost' => 5.00,
                    'new_unit_cost' => 69.00,
                    'suggested_sale_price' => 100.00,
                    'warehouse_id' => $outlet->id,
                    'work_order_status' => RefurbishedItem::WorkOrderCompleted,
                    'work_order_steps' => ['cleaning' => true, 'testing' => true, 'repackaging' => true],
                    'is_available' => true,
                ]
            );

            ReturnGradingInspection::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'inspection_number' => 'GRD-2026-0001'],
                [
                    'customer_return_id' => $returnOld->id,
                    'product_id' => $product->id,
                    'quantity_inspected' => 1,
                    'grade' => ReturnGradingInspection::GradeB,
                    'disposition' => ReturnGradingInspection::DispositionConvertBStock,
                    'checklist' => ['package_intact' => false, 'accessories_complete' => true, 'functional_test_passed' => true],
                    'photo_urls' => ['https://cdn.fatturafacile.local/returns/demo-grade-b.jpg'],
                    'original_unit_cost' => 80.00,
                    'devaluation_percent' => 20.00,
                    'revalued_unit_cost' => 64.00,
                    'target_warehouse_id' => $outlet->id,
                    'refurbished_item_id' => $refurbished->id,
                    'inspected_at' => now(),
                ]
            );
        });
    }
}
