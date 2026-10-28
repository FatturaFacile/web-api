<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
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

        $customer = Contact::firstOrCreate(
            ['tenant_id' => $tenantId, 'company_name' => 'Acme Corporation SpA'],
            [
                'id' => (string) Str::uuid7(),
                'type' => 'company',
                'email' => 'purchasing@acme-corp.example',
                'vat_number' => 'IT01234567890',
                'fiscal_code' => '01234567890',
                'sdi_code' => 'M5UXCR1',
                'pec' => 'acmecorp@pec.example',
                'country' => 'IT',
            ]
        );

        $product = Product::firstOrCreate(
            ['tenant_id' => $tenantId, 'sku' => 'SKU-ERP-LIC-001'],
            [
                'id' => (string) Str::uuid7(),
                'name' => 'ERP Cloud License Enterprise',
                'sale_price' => 1500.00,
                'cost_price' => 500.00,
                'tax_rate' => 22.0,
                'is_active' => true,
            ]
        );

        $quoteService = app(SalesQuoteServiceInterface::class);
        $orderService = app(SalesOrderServiceInterface::class);
        $fulfillmentService = app(DeliveryFulfillmentServiceInterface::class);
        $invoicingService = app(InvoicingServiceInterface::class);

        // 1. Create Sales Quote
        $quote = $quoteService->createQuote([
            'tenant_id' => $tenantId,
            'contact_id' => (string) $customer->id,
            'quote_number' => 'Q-DEMO-2027-001',
            'notes' => 'Demo Quote for Acme Corporation',
        ], [
            [
                'product_id' => (string) $product->id,
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
