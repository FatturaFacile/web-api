<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;

final class InvoicingDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        if ($tenant === null) {
            return;
        }

        $tenantId = (string) $tenant->id;
        app(TenantContext::class)->setTenant($tenant);

        // Check if demo quote already seeded
        $existingQuote = DB::table('quotes')->where('tenant_id', $tenantId)->where('quote_number', 'Q-DEMO-2027-001')->first();
        if ($existingQuote) {
            return;
        }

        $existingCustomer = DB::table('contacts')->where('tenant_id', $tenantId)->where('company_name', 'Acme Corporation SpA')->first();
        $customerId = $existingCustomer ? $existingCustomer->id : (string) Str::uuid7();

        if (! $existingCustomer) {
            DB::table('contacts')->insert([
                'id' => $customerId,
                'tenant_id' => $tenantId,
                'type' => 'client',
                'company_name' => 'Acme Corporation SpA',
                'email' => 'purchasing@acme-corp.example',
                'vat_number' => 'IT01234567890',
                'fiscal_code' => '01234567890',
                'sdi_code' => 'M5UXCR1',
                'pec' => 'acmecorp@pec.example',
                'country' => 'IT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingProduct = DB::table('products')->where('tenant_id', $tenantId)->where('sku', 'SKU-ERP-LIC-001')->first();
        $productId = $existingProduct ? $existingProduct->id : (string) Str::uuid7();

        if (! $existingProduct) {
            DB::table('products')->insert([
                'id' => $productId,
                'tenant_id' => $tenantId,
                'sku' => 'SKU-ERP-LIC-001',
                'name' => 'ERP Cloud License Enterprise',
                'sale_price' => 1500.00,
                'cost_price' => 500.00,
                'tax_rate' => 22.0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $quoteService = app(SalesQuoteServiceInterface::class);
        $orderService = app(SalesOrderServiceInterface::class);
        $fulfillmentService = app(DeliveryFulfillmentServiceInterface::class);
        $invoicingService = app(InvoicingServiceInterface::class);

        // 1. Create Sales Quote
        $quote = $quoteService->createQuote([
            'tenant_id' => $tenantId,
            'contact_id' => $customerId,
            'quote_number' => 'Q-DEMO-2027-001',
            'notes' => 'Demo Quote for Acme Corporation',
        ], [
            [
                'product_id' => $productId,
                'description' => 'ERP Cloud License Enterprise (Annual)',
                'quantity' => 10.0,
                'unit_price' => 1500.00,
                'tax_rate' => 22.0,
                'discount_percentage' => 10.0,
            ],
        ]);

        // Accept Quote & Convert to Order
        $quoteService->acceptQuote($quote);
        $order = $quoteService->convertToOrder($quote, 'demo-user');

        // Approve Order & Reserve Stock
        $orderService->submitOrder($order, 'demo-user');
        $orderService->approveOrder($order, 'demo-user');
        $orderService->reserveInventory($order);

        // Create Delivery Note & Dispatch
        $order->load('items');
        $orderItem = $order->items->first();

        $deliveryNote = $fulfillmentService->createDeliveryNote($order, [
            'ddt_number' => 'DDT-DEMO-2027-001',
            'transport_reason' => 'Vendita Licenze e Apparati',
        ], [
            [
                'sales_order_item_id' => (string) $orderItem->id,
                'quantity' => 10.0,
            ],
        ]);

        $fulfillmentService->dispatchDelivery($deliveryNote, 'demo-user');

        // Create Invoice from Order and Post
        $invoice = $invoicingService->createInvoiceFromOrder($order, [
            'invoice_number' => 'INV-DEMO-2027-001',
            'notes' => 'Demo Invoicing Lifecycle Complete',
        ]);

        $invoicingService->postInvoice($invoice, 'demo-user');
    }
}
