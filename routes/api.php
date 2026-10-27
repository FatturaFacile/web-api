<?php

declare(strict_types=1);

use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\V1\Accounting\GeneralLedgerController;
use App\Http\Controllers\Api\V1\Accounting\VendorAgingController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomotiveController;
use App\Http\Controllers\Api\V1\Banking\BankStatementController;
use App\Http\Controllers\Api\V1\ConstructionController;
use App\Http\Controllers\Api\V1\ContactInsightsController;
use App\Http\Controllers\Api\V1\CrmController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardBuilderController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeliveryNoteController;
use App\Http\Controllers\Api\V1\DocumentEmailController;
use App\Http\Controllers\Api\V1\DocumentPdfController;
use App\Http\Controllers\Api\V1\Ecommerce\EcommerceChannelController;
use App\Http\Controllers\Api\V1\Ecommerce\EcommerceWebhookController;
use App\Http\Controllers\Api\V1\FieldServiceController;
use App\Http\Controllers\Api\V1\FleetRentalController;
use App\Http\Controllers\Api\V1\HrController;
use App\Http\Controllers\Api\V1\IncomingInvoiceWebhookController;
use App\Http\Controllers\Api\V1\Inventory\ProductBarcodeController;
use App\Http\Controllers\Api\V1\Inventory\ProductLabelController;
use App\Http\Controllers\Api\V1\Inventory\ProductVariantController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use App\Http\Controllers\Api\V1\InvoiceQrBillController;
use App\Http\Controllers\Api\V1\InvoiceSdiController;
use App\Http\Controllers\Api\V1\LoyaltyCardController;
use App\Http\Controllers\Api\V1\ManufacturingController;
use App\Http\Controllers\Api\V1\PaymentImportController;
use App\Http\Controllers\Api\V1\PaymentReminderController;
use App\Http\Controllers\Api\V1\PaymentTermController;
use App\Http\Controllers\Api\V1\PosSaleController;
use App\Http\Controllers\Api\V1\PosSessionController;
use App\Http\Controllers\Api\V1\PosTerminalController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\ProductBrandController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProfessionalsController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchasesController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RetailPosController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\SalesOrderLineController;
use App\Http\Controllers\Api\V1\SdiWebhookController;
use App\Http\Controllers\Api\V1\ServiceReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\System\BootstrapSupportUserController;
use App\Http\Controllers\Api\V1\System\TenantBackupController;
use App\Http\Controllers\Api\V1\TenantPreferencesController;
use App\Http\Controllers\Api\V1\TimeAttendanceController;
use App\Http\Controllers\Api\V1\TreasuryController;
use App\Http\Controllers\Api\V1\UnitOfMeasureController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    // ----------------------------------------------------
    // Public Auth & Bootstrap Routes
    // ----------------------------------------------------
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::get('/system/bootstrap-support', BootstrapSupportUserController::class)->name('system.bootstrap-support');

    Route::get('/translations/{locale}', [TranslationController::class, 'index'])->name('translations.index');
    Route::get('/lookups/{category_code}', [LookupController::class, 'getByCategory'])->name('lookups.getByCategory');

    // ----------------------------------------------------
    // SDI provider webhook (authenticated by shared secret)
    // ----------------------------------------------------
    Route::post('/webhooks/sdi', SdiWebhookController::class)->middleware('throttle:120,1')->name('webhooks.sdi');
    Route::post('/webhooks/sdi/incoming-invoice', IncomingInvoiceWebhookController::class)->middleware('throttle:120,1')->name('webhooks.sdi.incoming-invoice');
    Route::post('/webhooks/ecommerce/{channelUuid}', EcommerceWebhookController::class)->middleware('throttle:300,1')->name('webhooks.ecommerce');

    // ----------------------------------------------------
    // Protected Enterprise Routes (Sanctum + Tenant + Locale)
    // ----------------------------------------------------
    Route::middleware(['auth:sanctum', 'tenant', 'locale'])->group(function (): void {

        Route::post('/translations', [TranslationController::class, 'storeOrUpdate'])->name('translations.storeOrUpdate');
        Route::post('/lookups/translations', [LookupController::class, 'saveTranslation'])->name('lookups.saveTranslation');

        // 1. Authentication & Context
        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
            Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
            Route::post('/switch-tenant', [AuthController::class, 'switchTenant'])->name('switch-tenant');
        });

        // 2. Executive Dashboard & Financial Health
        Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
            Route::get('/kpi-summary', [DashboardController::class, 'kpiSummary'])->name('kpi-summary');
            Route::get('/cash-flow-forecast', [DashboardController::class, 'cashFlowForecast'])->name('cash-flow-forecast');
            Route::get('/tax-deadlines', [DashboardController::class, 'taxDeadlines'])->name('tax-deadlines');
            Route::get('/available-widgets', [DashboardBuilderController::class, 'availableWidgets'])->name('available-widgets');
            Route::get('/layout', [DashboardBuilderController::class, 'showLayout'])->name('layout');
            Route::put('/layout', [DashboardBuilderController::class, 'updateLayout'])->name('layout.update');
            Route::post('/layout/reset', [DashboardBuilderController::class, 'resetLayout'])->name('layout.reset');
            Route::get('/data', [DashboardBuilderController::class, 'data'])->name('data');
        });

        // 3. CRM & Contacts
        Route::prefix('crm')->name('crm.')->group(function (): void {
            Route::get('/clients', [CrmController::class, 'clients'])->name('clients');
            Route::get('/suppliers', [CrmController::class, 'suppliers'])->name('suppliers');
            Route::get('/contacts/{id}/statistics', [ContactInsightsController::class, 'statistics'])->name('contacts.statistics');
            Route::get('/contacts/{id}/statement', [ContactInsightsController::class, 'statement'])->name('contacts.statement');
            Route::post('/contacts/{id}/statement/email', [ContactInsightsController::class, 'emailStatement'])->name('contacts.statement.email');
            Route::apiResource('contacts', CrmController::class);
        });

        // 4. Sales, Invoicing & Cross-Border Billing
        Route::prefix('sales')->name('sales.')->group(function (): void {
            Route::apiResource('invoices', InvoiceController::class);
            Route::post('/invoices/{id}/generate-sdi-xml', [InvoiceController::class, 'generateSdiXml'])->name('invoices.generate-sdi-xml');
            Route::post('/invoices/{id}/generate-qr-bill', [InvoiceController::class, 'generateQrBill'])->middleware('deprecated:/api/v1/sales/invoices/{id}/qr-bill')->name('invoices.generate-qr-bill');
            Route::get('/invoices/{id}/download-qr-bill', [InvoiceController::class, 'downloadQrBill'])->middleware('deprecated:/api/v1/sales/invoices/{id}/qr-bill')->name('invoices.download-qr-bill');
            Route::get('/invoices/{id}/qr-bill', [InvoiceQrBillController::class, 'show'])->name('invoices.qr-bill');
            Route::get('/invoices/{id}/payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
            Route::post('/invoices/{id}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
            Route::post('/invoices/{id}/convert-proforma', [InvoicePaymentController::class, 'convertProforma'])->name('invoices.convert-proforma');
            Route::get('/invoices/{id}/reminders', [PaymentReminderController::class, 'index'])->name('invoices.reminders.index');
            Route::post('/invoices/{id}/reminders', [PaymentReminderController::class, 'store'])->name('invoices.reminders.store');

            Route::post('/invoices/{id}/send-sdi', [InvoiceSdiController::class, 'send'])->name('invoices.send-sdi');
            Route::get('/invoices/{id}/sdi-logs', [InvoiceSdiController::class, 'logs'])->name('invoices.sdi-logs');

            Route::get('/payment-imports', [PaymentImportController::class, 'index'])->name('payment-imports.index');
            Route::post('/payment-imports', [PaymentImportController::class, 'store'])->name('payment-imports.store');
            Route::get('/payment-imports/{id}', [PaymentImportController::class, 'show'])->name('payment-imports.show');
            Route::post('/payment-imports/{id}/lines/{lineId}/match', [PaymentImportController::class, 'matchLine'])->name('payment-imports.lines.match');

            Route::apiResource('quotes', QuoteController::class);
            Route::post('/quotes/{id}/convert-to-order', [QuoteController::class, 'convertToOrder'])->name('quotes.convert-to-order');
            Route::post('/quotes/{id}/invoice', [QuoteController::class, 'createInvoice'])->name('quotes.invoice');
            Route::post('/quotes/{id}/delivery-note', [QuoteController::class, 'createDeliveryNote'])->name('quotes.delivery-note');
            Route::post('/quotes/{id}/signature', [QuoteController::class, 'sign'])->name('quotes.signature');

            Route::apiResource('orders', SalesOrderController::class)->parameters(['orders' => 'id']);
            Route::post('/orders/{id}/delivery-notes', [SalesOrderController::class, 'createDeliveryNote'])->name('orders.delivery-notes.store');
            Route::post('/orders/{id}/invoice', [SalesOrderController::class, 'createInvoice'])->name('orders.invoice');
            Route::post('/orders/{id}/deposit-invoice', [SalesOrderController::class, 'createDepositInvoice'])->name('orders.deposit-invoice');
            Route::get('/order-lines', [SalesOrderLineController::class, 'index'])->name('order-lines.index');

            Route::apiResource('delivery-notes', DeliveryNoteController::class)->only(['index', 'show', 'destroy'])->parameters(['delivery-notes' => 'id']);
            Route::post('/delivery-notes/{id}/invoice', [DeliveryNoteController::class, 'createInvoice'])->name('delivery-notes.invoice');
        });

        // 4b. Catalog, Price Lists & Payment Terms
        Route::prefix('catalog')->name('catalog.')->group(function (): void {
            Route::apiResource('categories', ProductCategoryController::class)->parameters(['categories' => 'id']);
            Route::apiResource('brands', ProductBrandController::class)->parameters(['brands' => 'id']);
            Route::apiResource('units', UnitOfMeasureController::class)->parameters(['units' => 'id']);
            Route::get('/price-lists/resolve-price', [PriceListController::class, 'resolve'])->name('price-lists.resolve-price');
            Route::apiResource('price-lists', PriceListController::class)->parameters(['price-lists' => 'id']);
            Route::get('/payment-terms/{id}/schedule', [PaymentTermController::class, 'schedule'])->name('payment-terms.schedule');
            Route::apiResource('payment-terms', PaymentTermController::class)->parameters(['payment-terms' => 'id']);
        });

        // 4c. Subscriptions (recurring billing)
        Route::prefix('subscriptions')->name('subscriptions.')->group(function (): void {
            Route::post('/{id}/pause', [SubscriptionController::class, 'pause'])->name('pause');
            Route::post('/{id}/resume', [SubscriptionController::class, 'resume'])->name('resume');
            Route::post('/{id}/cancel', [SubscriptionController::class, 'cancel'])->name('cancel');
            Route::post('/{id}/bill', [SubscriptionController::class, 'bill'])->name('bill');
            Route::get('/{id}/runs', [SubscriptionController::class, 'runs'])->name('runs');
        });
        Route::apiResource('subscriptions', SubscriptionController::class)->parameters(['subscriptions' => 'id']);
        // 4d. Alerts & Loyalty cards
        Route::get('/alerts/active', [AlertController::class, 'active'])->name('alerts.active');
        Route::post('/documents/{type}/{id}/email', [DocumentEmailController::class, 'store'])->name('documents.email.store');
        Route::get('/documents/{type}/{id}/emails', [DocumentEmailController::class, 'index'])->name('documents.email.index');
        Route::get('/documents/{type}/{id}/pdf', [DocumentPdfController::class, 'show'])->name('documents.pdf');
        Route::get('/attachments', [AttachmentController::class, 'index'])->name('attachments.index');
        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::get('/attachments/{id}/download', [AttachmentController::class, 'download'])->name('attachments.download');
        Route::delete('/attachments/{id}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::apiResource('alerts', AlertController::class)->parameters(['alerts' => 'id']);
        Route::prefix('loyalty-cards')->name('loyalty-cards.')->group(function (): void {
            Route::get('/{id}/transactions', [LoyaltyCardController::class, 'transactions'])->name('transactions.index');
            Route::post('/{id}/transactions', [LoyaltyCardController::class, 'storeTransaction'])->name('transactions.store');
        });
        Route::apiResource('loyalty-cards', LoyaltyCardController::class)->parameters(['loyalty-cards' => 'id']);
        // 4e. E-commerce channels (WooCommerce, Shopify, PrestaShop)
        Route::prefix('ecommerce')->name('ecommerce.')->group(function (): void {
            Route::post('/channels/{id}/test-connection', [EcommerceChannelController::class, 'testConnection'])->name('channels.test-connection');
            Route::post('/channels/{id}/sync', [EcommerceChannelController::class, 'sync'])->name('channels.sync');
            Route::apiResource('channels', EcommerceChannelController::class)->parameters(['channels' => 'id']);
        });
        // 5. Purchasing & Accounts Payable (AP)
        Route::prefix('purchases')->name('purchases.')->group(function (): void {
            Route::get('/requests', [PurchasesController::class, 'requests'])->name('requests');
            Route::get('/orders', [PurchasesController::class, 'orders'])->name('orders');
            Route::get('/passive-invoices', [PurchasesController::class, 'passiveInvoices'])->name('passive-invoices');
            Route::post('/passive-invoices/import-sdi', [PurchasesController::class, 'importSdi'])->middleware('deprecated:/api/v1/purchases/passive-invoices/receive-xml')->name('passive-invoices.import-sdi');
            Route::post('/passive-invoices/scan-qr-bill', [PurchasesController::class, 'scanQrBill'])->name('passive-invoices.scan-qr-bill');
            Route::post('/export-pain001', [PurchasesController::class, 'exportPain001'])->name('export-pain001');
            Route::post('/orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
            Route::get('/orders/{id}', [PurchaseOrderController::class, 'show'])->name('orders.show');
            Route::put('/orders/{id}', [PurchaseOrderController::class, 'update'])->name('orders.update');
            Route::post('/orders/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->name('orders.confirm');
            Route::post('/orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('orders.receive');
            Route::get('/goods-receipts', [PurchaseOrderController::class, 'receipts'])->name('goods-receipts.index');
            Route::get('/goods-receipts/{id}', [PurchaseOrderController::class, 'showReceipt'])->name('goods-receipts.show');
            Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])->name('supplier-invoices.index');
            Route::get('/supplier-invoices/{id}', [SupplierInvoiceController::class, 'show'])->name('supplier-invoices.show');
            Route::post('/supplier-invoices/{id}/mark-paid', [SupplierInvoiceController::class, 'markPaid'])->name('supplier-invoices.mark-paid');
            Route::post('/passive-invoices/receive-xml', [SupplierInvoiceController::class, 'receiveXml'])->name('passive-invoices.receive-xml');
        });

        // 6. Treasury, Banking & Cash Management
        Route::prefix('treasury')->name('treasury.')->group(function (): void {
            Route::get('/bank-accounts', [TreasuryController::class, 'bankAccounts'])->name('bank-accounts');
            Route::get('/transactions', [TreasuryController::class, 'transactions'])->name('transactions');
            Route::post('/reconciliation/auto-match', [TreasuryController::class, 'autoMatch'])->name('reconciliation.auto-match');
            Route::post('/import-camt', [TreasuryController::class, 'importCamt'])->middleware('deprecated:/api/v1/sales/payment-imports')->name('import-camt');
            Route::post('/import-cbi', [TreasuryController::class, 'importCbi'])->name('import-cbi');
        });

        // 7. Inventory & Logistics
        Route::prefix('inventory')->name('inventory.')->group(function (): void {
            Route::get('/products', [InventoryController::class, 'products'])->name('products');
            Route::post('/products', [InventoryController::class, 'storeProduct'])->name('products.store');
            Route::put('/products/{id}', [InventoryController::class, 'updateProduct'])->name('products.update');
            Route::get('/warehouses', [InventoryController::class, 'warehouses'])->name('warehouses');
            Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
            Route::get('/delivery-notes', [InventoryController::class, 'deliveryNotes'])->name('delivery-notes');
            Route::get('/stock-levels', [StockController::class, 'levels'])->name('stock-levels');
            Route::post('/stock-adjustments', [StockController::class, 'adjust'])->name('stock-adjustments.store');
            Route::get('/products/variants/{variantId}', [ProductVariantController::class, 'show'])->name('products.variants.show');
            Route::put('/products/variants/{variantId}', [ProductVariantController::class, 'update'])->name('products.variants.update');
            Route::delete('/products/variants/{variantId}', [ProductVariantController::class, 'destroy'])->name('products.variants.destroy');
            Route::get('/products/{parentId}/variants', [ProductVariantController::class, 'index'])->name('products.variants.index');
            Route::post('/products/{parentId}/variants', [ProductVariantController::class, 'store'])->name('products.variants.store');
            Route::post('/products/{parentId}/variants/generate', [ProductVariantController::class, 'generate'])->name('products.variants.generate');
            Route::get('/products/{id}/barcode', [ProductBarcodeController::class, 'show'])->name('products.barcode');
            Route::get('/labels/presets', [ProductLabelController::class, 'presets'])->name('labels.presets');
            Route::post('/labels/print', [ProductLabelController::class, 'print'])->name('labels.print');
        });

        // 8. Service Tickets & Field Assistance (Rapportini)
        Route::prefix('field-service')->name('field-service.')->group(function (): void {
            Route::get('/tickets', [FieldServiceController::class, 'tickets'])->name('tickets');
            Route::get('/work-orders', [FieldServiceController::class, 'workOrders'])->name('work-orders');
            Route::post('/work-orders/{id}/sign', [FieldServiceController::class, 'signWorkOrder'])->middleware('deprecated:/api/v1/field-service/work-orders/{id}/signature')->name('work-orders.sign');
            Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
            Route::get('/work-orders/{id}', [WorkOrderController::class, 'show'])->name('work-orders.show');
            Route::put('/work-orders/{id}', [WorkOrderController::class, 'update'])->name('work-orders.update');
            Route::post('/work-orders/{id}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');
            Route::post('/work-orders/{id}/signature', [WorkOrderController::class, 'signature'])->name('work-orders.signature');
            Route::post('/work-orders/{id}/invoice', [WorkOrderController::class, 'invoice'])->name('work-orders.invoice');
        });

        // Module 01: Service Reports (Rapportini di Intervento)
        Route::prefix('service-reports')->name('service-reports.')->group(function (): void {
            Route::post('/generate-consolidated-invoice', [ServiceReportController::class, 'generateConsolidatedInvoice'])->name('generate-consolidated-invoice');
            Route::get('/', [ServiceReportController::class, 'index'])->name('index');
            Route::post('/', [ServiceReportController::class, 'store'])->name('store');
            Route::get('/{id}', [ServiceReportController::class, 'show'])->name('show');
            Route::put('/{id}', [ServiceReportController::class, 'update'])->name('update');
            Route::patch('/{id}', [ServiceReportController::class, 'update'])->name('patch');
            Route::delete('/{id}', [ServiceReportController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/sign', [ServiceReportController::class, 'sign'])->name('sign');
            Route::post('/{id}/generate-invoice', [ServiceReportController::class, 'generateInvoice'])->name('generate-invoice');
            Route::post('/{id}/upload-photo', [ServiceReportController::class, 'uploadPhoto'])->name('upload-photo');
            Route::get('/{id}/pdf', [ServiceReportController::class, 'pdf'])->name('pdf');
        });

        // 9. Automotive & Tire Center (Gommista)
        Route::prefix('automotive')->name('automotive.')->group(function (): void {
            Route::get('/vehicles', [AutomotiveController::class, 'vehicles'])->name('vehicles');
            Route::post('/tire-hotel/check-in', [AutomotiveController::class, 'tireHotelCheckIn'])->name('tire-hotel.check-in');
            Route::post('/tire-hotel/{id}/check-out', [AutomotiveController::class, 'tireHotelCheckOut'])->name('tire-hotel.check-out');
            Route::get('/tire-hotel/seasonal-switch-plan', [AutomotiveController::class, 'seasonalSwitchPlan'])->name('tire-hotel.seasonal-switch-plan');
            Route::get('/inspections/due-list', [AutomotiveController::class, 'inspectionsDueList'])->name('inspections.due-list');
            Route::post('/inspections/{id}/complete', [AutomotiveController::class, 'completeInspection'])->name('inspections.complete');
        });

        // 10. Fleet Rental
        Route::prefix('rent')->name('rent.')->group(function (): void {
            Route::get('/vehicles', [FleetRentalController::class, 'vehicles'])->name('vehicles');
            Route::get('/bookings', [FleetRentalController::class, 'bookings'])->name('bookings');
            Route::post('/check-in-inspection', [FleetRentalController::class, 'checkInInspection'])->name('check-in-inspection');
            Route::post('/check-out-inspection', [FleetRentalController::class, 'checkOutInspection'])->name('check-out-inspection');
        });

        // 11. Construction & Craftsmen (Cantieri)
        Route::prefix('construction')->name('construction.')->group(function (): void {
            Route::get('/sites', [ConstructionController::class, 'sites'])->name('sites');
            Route::get('/sal', [ConstructionController::class, 'sal'])->name('sal');
            Route::post('/sal', [ConstructionController::class, 'storeSal'])->name('sal.store');
            Route::get('/conformity-certificates', [ConstructionController::class, 'conformityCertificates'])->name('conformity-certificates');
        });

        // 12. Professional Practices (Fascicoli)
        Route::prefix('professionals')->name('professionals.')->group(function (): void {
            Route::get('/cases', [ProfessionalsController::class, 'cases'])->name('cases');
            Route::get('/time-logs', [ProfessionalsController::class, 'timeLogs'])->name('time-logs');
            Route::post('/time-logs', [ProfessionalsController::class, 'logActivity'])->name('time-logs.store');
            Route::get('/fee-notes', [ProfessionalsController::class, 'feeNotes'])->name('fee-notes');
            Route::post('/fee-notes', [ProfessionalsController::class, 'storeFeeNote'])->name('fee-notes.store');
        });

        // 13. Manufacturing (MRP)
        Route::prefix('manufacturing')->name('manufacturing.')->group(function (): void {
            Route::get('/bom', [ManufacturingController::class, 'bom'])->name('bom');
            Route::get('/production-orders', [ManufacturingController::class, 'productionOrders'])->name('production-orders');
            Route::post('/stages/{stageId}/track', [ManufacturingController::class, 'trackStage'])->name('stages.track');
        });

        // 14. Retail & POS
        Route::prefix('retail')->name('retail.')->group(function (): void {
            Route::get('/pos/sessions', [RetailPosController::class, 'sessions'])->name('pos.sessions');
            Route::post('/pos/checkout', [RetailPosController::class, 'checkout'])->middleware('deprecated:/api/v1/retail/pos/sales')->name('pos.checkout');
            Route::post('/pos/rt-sync', [RetailPosController::class, 'rtSync'])->middleware('deprecated:/api/v1/retail/pos/sales/{id}/rt-transmit')->name('pos.rt-sync');
            Route::get('/pos/terminals', [PosTerminalController::class, 'index'])->name('pos.terminals.index');
            Route::post('/pos/terminals', [PosTerminalController::class, 'store'])->name('pos.terminals.store');
            Route::post('/pos/sessions', [PosSessionController::class, 'store'])->name('pos.sessions.store');
            Route::post('/pos/sessions/{id}/close', [PosSessionController::class, 'close'])->name('pos.sessions.close');
            Route::get('/pos/sessions/{id}/report', [PosSessionController::class, 'report'])->name('pos.sessions.report');
            Route::post('/pos/sales', [PosSaleController::class, 'store'])->name('pos.sales.store');
            Route::get('/pos/sales/{id}', [PosSaleController::class, 'show'])->name('pos.sales.show');
            Route::post('/pos/sales/{id}/refund', [PosSaleController::class, 'refund'])->name('pos.sales.refund');
            Route::post('/pos/sales/{id}/rt-transmit', [PosSaleController::class, 'rtTransmit'])->name('pos.sales.rt-transmit');
        });

        // 15. Time & Attendance (Rilevazione Presenze)
        Route::prefix('time-attendance')->name('time-attendance.')->group(function (): void {
            Route::get('/entries', [TimeAttendanceController::class, 'index'])->name('entries');
            Route::post('/clock-in', [TimeAttendanceController::class, 'clockIn'])->name('clock-in');
            Route::post('/clock-out', [TimeAttendanceController::class, 'clockOut'])->name('clock-out');
            Route::get('/compliance-check', [TimeAttendanceController::class, 'complianceCheck'])->name('compliance-check');
            Route::get('/monthly-report', [TimeAttendanceController::class, 'monthlyReport'])->name('monthly-report');
        });

        // 16. Cross-Border HR & Payroll
        Route::prefix('hr')->name('hr.')->group(function (): void {
            Route::get('/employees', [HrController::class, 'employees'])->name('employees');
            Route::get('/frontalieri', [HrController::class, 'frontalieri'])->name('frontalieri');
            Route::get('/withholding-taxes', [HrController::class, 'withholdingTaxes'])->name('withholding-taxes');
        });

        // 17. Fiscal & Statutory Accounting
        Route::prefix('accounting')->name('accounting.')->group(function (): void {
            Route::get('/chart-of-accounts', [AccountingController::class, 'chartOfAccounts'])->name('chart-of-accounts');
            Route::get('/ledger-entries', [AccountingController::class, 'ledgerEntries'])->name('ledger-entries');
            Route::post('/ledger-entries', [AccountingController::class, 'storeLedgerEntry'])->name('ledger-entries.store');
            Route::get('/vat-settlements', [AccountingController::class, 'vatSettlements'])->name('vat-settlements');
            Route::get('/vendor-aging', VendorAgingController::class)->name('vendor-aging');
            Route::get('/general-ledger', [GeneralLedgerController::class, 'index'])->name('general-ledger');
            Route::get('/general-ledger/export', [GeneralLedgerController::class, 'export'])->name('general-ledger.export');
        });

        // 18. Settings & Multi-Tenant Management
        // Banking: statement import and reconciliation
        Route::prefix('banking')->name('banking.')->group(function (): void {
            Route::get('/statements', [BankStatementController::class, 'index'])->name('statements.index');
            Route::post('/statements/import', [BankStatementController::class, 'import'])->name('statements.import');
            Route::post('/statements/lines/{lineId}/manual-match', [BankStatementController::class, 'manualMatch'])->name('statements.lines.manual-match');
            Route::post('/statements/lines/{lineId}/categorize', [BankStatementController::class, 'categorize'])->name('statements.lines.categorize');
            Route::post('/statements/lines/{lineId}/unmatch', [BankStatementController::class, 'unmatch'])->name('statements.lines.unmatch');
            Route::get('/statements/{id}', [BankStatementController::class, 'show'])->name('statements.show');
            Route::post('/statements/{id}/reconcile', [BankStatementController::class, 'reconcile'])->name('statements.reconcile');
        });

        // System: manual tenant backups
        Route::prefix('system')->name('system.')->group(function (): void {
            Route::get('/backups', [TenantBackupController::class, 'index'])->name('backups.index');
            Route::post('/backups', [TenantBackupController::class, 'store'])->name('backups.store');
            Route::post('/backups/restore-upload', [TenantBackupController::class, 'restoreUpload'])->name('backups.restore-upload');
            Route::get('/backups/{id}', [TenantBackupController::class, 'show'])->name('backups.show');
            Route::post('/backups/{id}/restore', [TenantBackupController::class, 'restore'])->name('backups.restore');
            Route::get('/backups/{id}/download', [TenantBackupController::class, 'download'])->name('backups.download');
            Route::delete('/backups/{id}', [TenantBackupController::class, 'destroy'])->name('backups.destroy');
        });

        Route::prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/tenant', [SettingsController::class, 'tenant'])->name('tenant');
            Route::get('/preferences', [TenantPreferencesController::class, 'index'])->name('preferences');
            Route::put('/preferences/{group}', [TenantPreferencesController::class, 'update'])->name('preferences.update');
            Route::put('/tenant', [SettingsController::class, 'updateTenant'])->name('tenant.update');
            Route::get('/branches', [SettingsController::class, 'branches'])->name('branches');
            Route::post('/exchange-rates/sync', [SettingsController::class, 'syncExchangeRates'])->name('exchange-rates.sync');
            Route::get('/users', [SettingsController::class, 'users'])->name('users');
            Route::get('/roles', [SettingsController::class, 'roles'])->name('roles');
            Route::get('/audit-logs', [SettingsController::class, 'auditLogs'])->name('audit-logs');
        });
    });
});

Route::get('/lookups/{category_code}', [LookupController::class, 'getByCategory'])->name('api.lookups.getByCategory');
Route::post('/lookups/translations', [LookupController::class, 'saveTranslation'])->name('api.lookups.saveTranslation');
