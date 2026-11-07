<?php

declare(strict_types=1);

use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\V1\Accounting\GeneralLedgerController;
use App\Http\Controllers\Api\V1\Accounting\VendorAgingController;
use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\Admin\TenantAccessRequestController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AppointmentController;
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
use App\Http\Controllers\Api\V1\DocumentQuotaController;
use App\Http\Controllers\Api\V1\DocumentTemplateController;
use App\Http\Controllers\Api\V1\Ecommerce\EcommerceChannelController;
use App\Http\Controllers\Api\V1\Ecommerce\EcommerceWebhookController;
use App\Http\Controllers\Api\V1\ExpenseController;
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
use App\Http\Controllers\Api\V1\PasswordResetController;
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
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\SalesOrderLineController;
use App\Http\Controllers\Api\V1\SdiWebhookController;
use App\Http\Controllers\Api\V1\ServiceReportController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierCostRuleController;
use App\Http\Controllers\Api\V1\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\System\BootstrapSupportUserController;
use App\Http\Controllers\Api\V1\System\TenantBackupController;
use App\Http\Controllers\Api\V1\TenantPreferencesController;
use App\Http\Controllers\Api\V1\TimeAttendanceController;
use App\Http\Controllers\Api\V1\TreasuryController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UnitOfMeasureController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use App\Http\Controllers\Portal\CustomerPortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {

    // ----------------------------------------------------
    // Public Auth & Bootstrap Routes
    // ----------------------------------------------------
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['locale', 'throttle:login'])->name('auth.login');
    Route::post('/auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware(['locale', 'throttle:two-factor-challenge'])->name('auth.two-factor-challenge');
    Route::middleware('locale')->prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register', [RegistrationController::class, 'register'])->middleware('throttle:registration')->name('register');
        Route::get('/verify-email', [RegistrationController::class, 'verify'])->middleware('throttle:email-verification')->name('verify-email');
        Route::post('/resend-verification-email', [RegistrationController::class, 'resend'])->middleware('throttle:verification-resend')->name('resend-verification-email');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset-request')->name('forgot-password');
        Route::post('/resend-reset-password-email', [PasswordResetController::class, 'resend'])->middleware('throttle:password-reset-request')->name('resend-reset-password-email');
        Route::get('/validate-reset-token', [PasswordResetController::class, 'validateToken'])->middleware('throttle:password-reset-attempt')->name('validate-reset-token');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset-attempt')->name('reset-password');
    });

    // Platform administrators work across tenants, so these routes have no tenant context.
    Route::middleware(['auth:sanctum', 'platform.admin', 'locale'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/tenant-access-requests', [TenantAccessRequestController::class, 'index'])->name('tenant-access-requests.index');
        Route::post('/tenant-access-requests/{id}/approve', [TenantAccessRequestController::class, 'approve'])->name('tenant-access-requests.approve');
        Route::post('/tenant-access-requests/{id}/move', [TenantAccessRequestController::class, 'move'])->name('tenant-access-requests.move');
        Route::put('/tenants/{id}/document-quota', [DocumentQuotaController::class, 'update'])->name('tenants.document-quota');
        Route::post('/tenant-access-requests/{id}/reject', [TenantAccessRequestController::class, 'reject'])->name('tenant-access-requests.reject');
    });

    Route::get('/system/bootstrap-support', BootstrapSupportUserController::class)
        ->middleware('throttle:5,1')
        ->name('system.bootstrap-support');

    Route::get('/translations/{locale}', [TranslationController::class, 'index'])->name('translations.index');
    Route::get('/lookups/{category_code}', [LookupController::class, 'getByCategory'])->name('lookups.getByCategory');

    // ----------------------------------------------------
    // SDI provider webhook (authenticated by shared secret)
    // ----------------------------------------------------
    Route::post('/webhooks/sdi', SdiWebhookController::class)->middleware('throttle:120,1')->name('webhooks.sdi');
    Route::post('/webhooks/sdi/incoming-invoice', IncomingInvoiceWebhookController::class)->middleware('throttle:120,1')->name('webhooks.sdi.incoming-invoice');
    Route::post('/webhooks/ecommerce/{channelUuid}', EcommerceWebhookController::class)->middleware('throttle:300,1')->name('webhooks.ecommerce');

    // ----------------------------------------------------
    // Module 09: Customer Portal Magic Link API (Public Token-Authenticated)
    // ----------------------------------------------------
    Route::prefix('portal')->name('portal.')->middleware('throttle:60,1')->group(function (): void {
        Route::get('/access/{token}', [CustomerPortalController::class, 'access'])->name('access');
        Route::get('/quotes/{token}', [CustomerPortalController::class, 'showQuote'])->name('quotes.show');
        Route::post('/quotes/{token}/approve', [CustomerPortalController::class, 'approveQuote'])->name('quotes.approve');
        Route::post('/quotes/{token}/reject', [CustomerPortalController::class, 'rejectQuote'])->name('quotes.reject');
        Route::get('/quotes/{token}/pdf', [CustomerPortalController::class, 'downloadQuotePdf'])->name('quotes.pdf');
        Route::get('/invoices/{token}', [CustomerPortalController::class, 'listInvoices'])->name('invoices.list');
        Route::get('/invoices/{token}/{id}/pdf', [CustomerPortalController::class, 'downloadInvoicePdf'])->name('invoices.pdf');
        Route::get('/service-reports/{token}', [CustomerPortalController::class, 'listServiceReports'])->name('service-reports.list');
        Route::get('/service-reports/{token}/{id}/pdf', [CustomerPortalController::class, 'downloadServiceReportPdf'])->name('service-reports.pdf');
        Route::get('/tickets/{token}', [CustomerPortalController::class, 'listTickets'])->name('tickets.list');
        Route::post('/tickets/{token}/reply/{ticketId}', [CustomerPortalController::class, 'replyTicket'])->name('tickets.reply');
        Route::get('/appointments/{token}', [CustomerPortalController::class, 'listAppointments'])->name('appointments.list');
        Route::get('/attachments/{token}/{attachment}', [CustomerPortalController::class, 'downloadAttachment'])->name('attachments.download');
    });

    // ----------------------------------------------------
    // Protected Enterprise Routes (Sanctum + Tenant + Locale)
    // ----------------------------------------------------
    Route::middleware(['auth:sanctum', 'tenant', 'locale'])->group(function (): void {

        // Every route below declares its module (config/permissions.php), except the ones listed in
        // tests/Feature/Foundation/RouteModuleAccessTest.php, which check the user themselves (own records, own profile).
        Route::middleware('module.access:system')->group(function (): void {
            Route::post('/translations', [TranslationController::class, 'storeOrUpdate'])->name('translations.storeOrUpdate');
            Route::post('/lookups/translations', [LookupController::class, 'saveTranslation'])->name('lookups.saveTranslation');
        });

        // 1. Authentication & Context
        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
            Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
            Route::post('/switch-tenant', [AuthController::class, 'switchTenant'])->name('switch-tenant');
            Route::get('/two-factor', [TwoFactorController::class, 'status'])->name('two-factor.status');
            Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
            Route::post('/sessions/revoke-others', [SessionController::class, 'revokeOthers'])->middleware('throttle:6,1')->name('sessions.revoke-others');
            Route::delete('/sessions/{kind}/{id}', [SessionController::class, 'destroy'])->whereIn('kind', ['token', 'session'])->where('id', '[A-Za-z0-9]{1,64}')->name('sessions.destroy');
            Route::post('/two-factor/enable', [TwoFactorController::class, 'enable'])->middleware('throttle:6,1')->name('two-factor.enable');
            Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1')->name('two-factor.confirm');
            Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:6,1')->name('two-factor.recovery-codes');
            Route::post('/two-factor/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1')->name('two-factor.disable');
        });

        // 2. Executive Dashboard & Financial Health
        Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
            Route::get('/kpi-summary', [DashboardController::class, 'kpiSummary'])->name('kpi-summary');
            Route::get('/cash-flow-forecast', [DashboardController::class, 'cashFlowForecast'])->middleware('module.access:treasury')->name('cash-flow-forecast');
            Route::get('/tax-deadlines', [DashboardController::class, 'taxDeadlines'])->name('tax-deadlines');
            Route::get('/available-widgets', [DashboardBuilderController::class, 'availableWidgets'])->name('available-widgets');
            Route::get('/layout', [DashboardBuilderController::class, 'showLayout'])->name('layout');
            Route::put('/layout', [DashboardBuilderController::class, 'updateLayout'])->name('layout.update');
            Route::post('/layout/reset', [DashboardBuilderController::class, 'resetLayout'])->name('layout.reset');
            Route::get('/data', [DashboardBuilderController::class, 'data'])->name('data');
        });

        // 3. CRM & Contacts
        Route::prefix('crm')->name('crm.')->middleware('module.access:crm')->group(function (): void {
            Route::get('/clients', [CrmController::class, 'clients'])->name('clients');
            Route::get('/suppliers', [CrmController::class, 'suppliers'])->name('suppliers');
            Route::get('/contacts/{id}/statistics', [ContactInsightsController::class, 'statistics'])->name('contacts.statistics');
            Route::get('/contacts/{id}/statement', [ContactInsightsController::class, 'statement'])->name('contacts.statement');
            Route::post('/contacts/{id}/statement/email', [ContactInsightsController::class, 'emailStatement'])->name('contacts.statement.email');
            Route::apiResource('contacts', CrmController::class)->parameters(['contacts' => 'id']);
        });

        // 4. Sales, Invoicing & Cross-Border Billing
        Route::prefix('sales')->name('sales.')->middleware('module.access:invoicing')->group(function (): void {
            Route::apiResource('invoices', InvoiceController::class)->parameters(['invoices' => 'id']);
            Route::post('/invoices/{id}/generate-sdi-xml', [InvoiceController::class, 'generateSdiXml'])->name('invoices.generate-sdi-xml');
            Route::post('/invoices/{id}/generate-qr-bill', [InvoiceController::class, 'generateQrBill'])->middleware('deprecated:/api/v1/sales/invoices/{id}/qr-bill')->name('invoices.generate-qr-bill');
            Route::get('/invoices/{id}/download-qr-bill', [InvoiceController::class, 'downloadQrBill'])->middleware('deprecated:/api/v1/sales/invoices/{id}/qr-bill')->name('invoices.download-qr-bill');
            Route::get('/invoices/{id}/qr-bill', [InvoiceQrBillController::class, 'show'])->name('invoices.qr-bill');
            Route::get('/invoices/{id}/payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
            Route::post('/invoices/{id}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
            Route::post('/invoices/{id}/convert-proforma', [InvoicePaymentController::class, 'convertProforma'])->name('invoices.convert-proforma');
            Route::get('/invoices/{id}/reminders', [PaymentReminderController::class, 'index'])->name('invoices.reminders.index');
            Route::post('/invoices/{id}/reminders', [PaymentReminderController::class, 'store'])->name('invoices.reminders.store');

            Route::post('/invoices/send-sdi-bulk', [InvoiceSdiController::class, 'sendBulk'])->name('invoices.send-sdi-bulk');
            Route::post('/invoices/{id}/send-sdi', [InvoiceSdiController::class, 'send'])->name('invoices.send-sdi');
            Route::get('/invoices/{id}/sdi-logs', [InvoiceSdiController::class, 'logs'])->name('invoices.sdi-logs');

            Route::get('/payment-imports', [PaymentImportController::class, 'index'])->name('payment-imports.index');
            Route::post('/payment-imports', [PaymentImportController::class, 'store'])->name('payment-imports.store');
            Route::get('/payment-imports/{id}', [PaymentImportController::class, 'show'])->name('payment-imports.show');
            Route::post('/payment-imports/{id}/lines/{lineId}/match', [PaymentImportController::class, 'matchLine'])->name('payment-imports.lines.match');

            Route::apiResource('quotes', QuoteController::class)->parameters(['quotes' => 'id']);
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
        Route::prefix('catalog')->name('catalog.')->middleware('module.access:invoicing')->group(function (): void {
            Route::apiResource('categories', ProductCategoryController::class)->parameters(['categories' => 'id']);
            Route::apiResource('brands', ProductBrandController::class)->parameters(['brands' => 'id']);
            Route::apiResource('units', UnitOfMeasureController::class)->parameters(['units' => 'id']);
            Route::get('/price-lists/resolve-price', [PriceListController::class, 'resolve'])->name('price-lists.resolve-price');
            Route::apiResource('price-lists', PriceListController::class)->parameters(['price-lists' => 'id']);
            Route::get('/payment-terms/{id}/schedule', [PaymentTermController::class, 'schedule'])->name('payment-terms.schedule');
            Route::apiResource('payment-terms', PaymentTermController::class)->parameters(['payment-terms' => 'id']);
        });

        // 4c. Subscriptions (recurring billing)
        Route::middleware('module.access:subscriptions')->group(function (): void {
            Route::prefix('subscriptions')->name('subscriptions.')->group(function (): void {
                Route::post('/{id}/pause', [SubscriptionController::class, 'pause'])->name('pause');
                Route::post('/{id}/resume', [SubscriptionController::class, 'resume'])->name('resume');
                Route::post('/{id}/cancel', [SubscriptionController::class, 'cancel'])->name('cancel');
                Route::post('/{id}/bill', [SubscriptionController::class, 'bill'])->name('bill');
                Route::get('/{id}/runs', [SubscriptionController::class, 'runs'])->name('runs');
            });
            Route::apiResource('subscriptions', SubscriptionController::class)->parameters(['subscriptions' => 'id']);
        });
        // 4d. Alerts & Loyalty cards
        // Alerts sit on contacts and sales documents; loyalty cards are part of the sales cycle.
        Route::middleware('module.access:invoicing')->group(function (): void {
            Route::get('/alerts/active', [AlertController::class, 'active'])->name('alerts.active');
            Route::apiResource('alerts', AlertController::class)->parameters(['alerts' => 'id']);
            Route::prefix('loyalty-cards')->name('loyalty-cards.')->group(function (): void {
                Route::get('/{id}/transactions', [LoyaltyCardController::class, 'transactions'])->name('transactions.index');
                Route::post('/{id}/transactions', [LoyaltyCardController::class, 'storeTransaction'])->name('transactions.store');
            });
            Route::apiResource('loyalty-cards', LoyaltyCardController::class)->parameters(['loyalty-cards' => 'id']);
        });
        // A document or an attachment belongs to several modules: the controllers check the module of the record.
        Route::post('/documents/{type}/{id}/email', [DocumentEmailController::class, 'store'])->name('documents.email.store');
        Route::get('/documents/{type}/{id}/emails', [DocumentEmailController::class, 'index'])->name('documents.email.index');
        Route::get('/documents/{type}/{id}/pdf', [DocumentPdfController::class, 'show'])->name('documents.pdf');
        Route::get('/attachments', [AttachmentController::class, 'index'])->name('attachments.index');
        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::get('/attachments/{id}/download', [AttachmentController::class, 'download'])->name('attachments.download');
        Route::delete('/attachments/{id}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
        // 4e. E-commerce channels (WooCommerce, Shopify, PrestaShop)
        Route::prefix('ecommerce')->name('ecommerce.')->middleware('module.access:integrations')->group(function (): void {
            Route::post('/channels/{id}/test-connection', [EcommerceChannelController::class, 'testConnection'])->name('channels.test-connection');
            Route::post('/channels/{id}/sync', [EcommerceChannelController::class, 'sync'])->name('channels.sync');
            Route::apiResource('channels', EcommerceChannelController::class)->parameters(['channels' => 'id']);
        });
        // 5. Purchasing & Accounts Payable (AP)
        Route::prefix('purchases')->name('purchases.')->middleware('module.access:procurement')->group(function (): void {
            Route::get('/requests', [PurchasesController::class, 'requests'])->name('requests');
            Route::get('/orders', [PurchasesController::class, 'orders'])->name('orders');
            Route::get('/passive-invoices', [PurchasesController::class, 'passiveInvoices'])->name('passive-invoices');
            Route::post('/passive-invoices/import-sdi', [PurchasesController::class, 'importSdi'])->middleware('deprecated:/api/v1/purchases/passive-invoices/receive-xml')->name('passive-invoices.import-sdi');
            Route::post('/passive-invoices/scan-qr-bill', [PurchasesController::class, 'scanQrBill'])->name('passive-invoices.scan-qr-bill');
            // Paying suppliers moves money: the treasury roles too.
            Route::post('/export-pain001', [PurchasesController::class, 'exportPain001'])->middleware('module.access:treasury')->name('export-pain001');
            Route::post('/orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
            Route::get('/orders/{id}', [PurchaseOrderController::class, 'show'])->name('orders.show');
            Route::put('/orders/{id}', [PurchaseOrderController::class, 'update'])->name('orders.update');
            Route::post('/orders/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->name('orders.confirm');
            Route::post('/orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('orders.receive');
            Route::get('/goods-receipts', [PurchaseOrderController::class, 'receipts'])->name('goods-receipts.index');
            Route::get('/goods-receipts/{id}', [PurchaseOrderController::class, 'showReceipt'])->name('goods-receipts.show');
            Route::middleware('module.access:ledger')->group(function (): void {
                Route::get('/cost-rules', [SupplierCostRuleController::class, 'index'])->name('cost-rules.index');
                Route::post('/cost-rules', [SupplierCostRuleController::class, 'store'])->name('cost-rules.store');
                Route::delete('/cost-rules/{id}', [SupplierCostRuleController::class, 'destroy'])->name('cost-rules.destroy');
                Route::put('/supplier-invoices/{id}/cost-account', [SupplierCostRuleController::class, 'overrideCostAccount'])->name('supplier-invoices.cost-account');
            });

            Route::get('/supplier-invoices/{id}/lines', [SupplierCostRuleController::class, 'lines'])->name('supplier-invoices.lines');
            Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])->name('supplier-invoices.index');
            Route::get('/supplier-invoices/{id}', [SupplierInvoiceController::class, 'show'])->name('supplier-invoices.show');
            Route::post('/supplier-invoices/{id}/mark-paid', [SupplierInvoiceController::class, 'markPaid'])->middleware('module.access:treasury')->name('supplier-invoices.mark-paid');
            Route::post('/passive-invoices/receive-xml', [SupplierInvoiceController::class, 'receiveXml'])->name('passive-invoices.receive-xml');
            Route::post('/passive-invoices/{id}/self-invoice', [SupplierInvoiceController::class, 'selfInvoice'])->name('passive-invoices.self-invoice');
        });

        // 6. Treasury, Banking & Cash Management (Enterprise module mounted in modules/24_Treasury)
        Route::prefix('treasury')->name('treasury.')->middleware('module.access:treasury')->group(function (): void {
            Route::get('/transactions', [TreasuryController::class, 'transactions'])->name('transactions');
            Route::post('/reconciliation/auto-match', [TreasuryController::class, 'autoMatch'])->name('reconciliation.auto-match');
            Route::post('/import-camt', [TreasuryController::class, 'importCamt'])->middleware('deprecated:/api/v1/sales/payment-imports')->name('import-camt');
            Route::post('/import-cbi', [TreasuryController::class, 'importCbi'])->name('import-cbi');
        });

        // 7. Inventory & Logistics
        Route::prefix('inventory')->name('inventory.')->middleware('module.access:inventory')->group(function (): void {
            Route::get('/products', [InventoryController::class, 'products'])->name('products');
            Route::post('/products', [InventoryController::class, 'storeProduct'])->name('products.store');
            Route::put('/products/{id}', [InventoryController::class, 'updateProduct'])->name('products.update');
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
        Route::prefix('field-service')->name('field-service.')->middleware('module.access:service')->group(function (): void {
            Route::get('/tickets', [FieldServiceController::class, 'tickets'])->name('tickets');
            Route::get('/work-orders', [FieldServiceController::class, 'workOrders'])->name('work-orders');
            Route::post('/work-orders/{id}/sign', [FieldServiceController::class, 'signWorkOrder'])->middleware('deprecated:/api/v1/field-service/work-orders/{id}/signature')->name('work-orders.sign');
            Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
            Route::get('/work-orders/{id}', [WorkOrderController::class, 'show'])->name('work-orders.show');
            Route::put('/work-orders/{id}', [WorkOrderController::class, 'update'])->name('work-orders.update');
            Route::post('/work-orders/{id}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');
            Route::post('/work-orders/{id}/signature', [WorkOrderController::class, 'signature'])->name('work-orders.signature');
            // Billing a job is an invoicing action, whoever did the job.
            Route::post('/work-orders/{id}/invoice', [WorkOrderController::class, 'invoice'])->middleware('module.access:invoicing')->name('work-orders.invoice');
        });

        // Module 01: Service Reports (Rapportini di Intervento)
        Route::prefix('service-reports')->name('service-reports.')->middleware('module.access:service')->group(function (): void {
            Route::post('/generate-consolidated-invoice', [ServiceReportController::class, 'generateConsolidatedInvoice'])->middleware('module.access:invoicing')->name('generate-consolidated-invoice');
            Route::get('/', [ServiceReportController::class, 'index'])->name('index');
            Route::post('/', [ServiceReportController::class, 'store'])->name('store');
            Route::get('/{id}', [ServiceReportController::class, 'show'])->name('show');
            Route::put('/{id}', [ServiceReportController::class, 'update'])->name('update');
            Route::patch('/{id}', [ServiceReportController::class, 'update'])->name('patch');
            Route::delete('/{id}', [ServiceReportController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/sign', [ServiceReportController::class, 'sign'])->name('sign');
            Route::post('/{id}/generate-invoice', [ServiceReportController::class, 'generateInvoice'])->middleware('module.access:invoicing')->name('generate-invoice');
            Route::post('/{id}/upload-photo', [ServiceReportController::class, 'uploadPhoto'])->name('upload-photo');
            Route::get('/{id}/pdf', [ServiceReportController::class, 'pdf'])->name('pdf');
        });

        // Module 10: Scheduling & Appointments
        Route::prefix('appointments')->name('appointments.')->middleware('module.access:service')->group(function (): void {
            Route::get('/calendar', [AppointmentController::class, 'calendar'])->name('calendar');
            Route::post('/check-conflicts', [AppointmentController::class, 'checkConflicts'])->name('check-conflicts');
            Route::get('/', [AppointmentController::class, 'index'])->name('index');
            Route::post('/', [AppointmentController::class, 'store'])->name('store');
            Route::get('/{id}', [AppointmentController::class, 'show'])->name('show');
            Route::put('/{id}', [AppointmentController::class, 'update'])->name('update');
            Route::patch('/{id}', [AppointmentController::class, 'update'])->name('patch');
            Route::delete('/{id}', [AppointmentController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/cancel', [AppointmentController::class, 'cancel'])->name('cancel');
            Route::post('/{id}/convert-to-service-report', [AppointmentController::class, 'convertToServiceReport'])->name('convert-to-service-report');
        });

        // 9. Automotive & Tire Center (Gommista)
        Route::prefix('automotive')->name('automotive.')->middleware('module.access:automotive')->group(function (): void {
            Route::get('/vehicles', [AutomotiveController::class, 'vehicles'])->name('vehicles');
            Route::post('/tire-hotel/check-in', [AutomotiveController::class, 'tireHotelCheckIn'])->name('tire-hotel.check-in');
            Route::post('/tire-hotel/{id}/check-out', [AutomotiveController::class, 'tireHotelCheckOut'])->name('tire-hotel.check-out');
            Route::get('/tire-hotel/seasonal-switch-plan', [AutomotiveController::class, 'seasonalSwitchPlan'])->name('tire-hotel.seasonal-switch-plan');
            Route::get('/inspections/due-list', [AutomotiveController::class, 'inspectionsDueList'])->name('inspections.due-list');
            Route::post('/inspections/{id}/complete', [AutomotiveController::class, 'completeInspection'])->name('inspections.complete');
        });

        // 10. Fleet Rental
        Route::prefix('rent')->name('rent.')->middleware('module.access:automotive')->group(function (): void {
            Route::get('/vehicles', [FleetRentalController::class, 'vehicles'])->name('vehicles');
            Route::get('/bookings', [FleetRentalController::class, 'bookings'])->name('bookings');
            Route::post('/check-in-inspection', [FleetRentalController::class, 'checkInInspection'])->name('check-in-inspection');
            Route::post('/check-out-inspection', [FleetRentalController::class, 'checkOutInspection'])->name('check-out-inspection');
        });

        // 11. Construction & Craftsmen (Cantieri)
        Route::prefix('construction')->name('construction.')->middleware('module.access:construction')->group(function (): void {
            Route::get('/sites', [ConstructionController::class, 'sites'])->name('sites');
            Route::get('/sal', [ConstructionController::class, 'sal'])->name('sal');
            Route::post('/sal', [ConstructionController::class, 'storeSal'])->name('sal.store');
            Route::get('/conformity-certificates', [ConstructionController::class, 'conformityCertificates'])->name('conformity-certificates');
        });

        // 12. Professional Practices (Fascicoli)
        Route::prefix('professionals')->name('professionals.')->middleware('module.access:professionals')->group(function (): void {
            Route::get('/cases', [ProfessionalsController::class, 'cases'])->name('cases');
            Route::get('/time-logs', [ProfessionalsController::class, 'timeLogs'])->name('time-logs');
            Route::post('/time-logs', [ProfessionalsController::class, 'logActivity'])->name('time-logs.store');
            Route::get('/fee-notes', [ProfessionalsController::class, 'feeNotes'])->name('fee-notes');
            Route::post('/fee-notes', [ProfessionalsController::class, 'storeFeeNote'])->name('fee-notes.store');
        });

        // 14. Retail & POS
        Route::prefix('retail')->name('retail.')->middleware('module.access:fiscal_retail')->group(function (): void {
            Route::get('/pos/sessions', [PosSessionController::class, 'index'])->name('pos.sessions');
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
        Route::prefix('hr')->name('hr.')->middleware('module.access:hr')->group(function (): void {
            Route::get('/frontalieri', [HrController::class, 'frontalieri'])->name('frontalieri');
            Route::get('/withholding-taxes', [HrController::class, 'withholdingTaxes'])->name('withholding-taxes');
        });

        // Module 12: Expense Tracking & Receipts
        Route::prefix('expenses')->name('expenses.')->group(function (): void {
            Route::get('/export', [ExpenseController::class, 'export'])->name('export');
            Route::get('/reports/summary', [ExpenseController::class, 'summary'])->name('summary');
            Route::post('/{id}/attachments', [ExpenseController::class, 'uploadAttachment'])->name('attachments.store');
            Route::get('/{id}/attachments/{attachmentId}/download', [ExpenseController::class, 'downloadAttachment'])->name('attachments.download');
        });
        Route::apiResource('expenses', ExpenseController::class)->parameters(['expenses' => 'id']);

        // 17. Fiscal & Statutory Accounting
        Route::prefix('accounting')->name('accounting.')->middleware('module.access:ledger')->group(function (): void {
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
        Route::prefix('banking')->name('banking.')->middleware('module.access:treasury')->group(function (): void {
            Route::get('/statements', [BankStatementController::class, 'index'])->name('statements.index');
            Route::post('/statements/import', [BankStatementController::class, 'import'])->name('statements.import');
            Route::post('/statements/lines/{lineId}/manual-match', [BankStatementController::class, 'manualMatch'])->name('statements.lines.manual-match');
            Route::post('/statements/lines/{lineId}/categorize', [BankStatementController::class, 'categorize'])->name('statements.lines.categorize');
            Route::post('/statements/lines/{lineId}/unmatch', [BankStatementController::class, 'unmatch'])->name('statements.lines.unmatch');
            Route::get('/statements/{id}', [BankStatementController::class, 'show'])->name('statements.show');
            Route::post('/statements/{id}/reconcile', [BankStatementController::class, 'reconcile'])->name('statements.reconcile');
        });

        // System: manual tenant backups
        Route::prefix('system')->name('system.')->middleware('module.access:system')->group(function (): void {
            Route::get('/backups', [TenantBackupController::class, 'index'])->name('backups.index');
            Route::post('/backups', [TenantBackupController::class, 'store'])->name('backups.store');
            Route::post('/backups/restore-upload', [TenantBackupController::class, 'restoreUpload'])->name('backups.restore-upload');
            Route::get('/backups/{id}', [TenantBackupController::class, 'show'])->name('backups.show');
            Route::post('/backups/{id}/restore', [TenantBackupController::class, 'restore'])->name('backups.restore');
            Route::get('/backups/{id}/download', [TenantBackupController::class, 'download'])->name('backups.download');
            Route::delete('/backups/{id}', [TenantBackupController::class, 'destroy'])->name('backups.destroy');
        });

        Route::prefix('settings')->name('settings.')->group(function (): void {
            // Every member reads the company profile, its preferences and branches: the clients need them on every page.
            Route::get('/tenant', [SettingsController::class, 'tenant'])->name('tenant');
            Route::get('/preferences', [TenantPreferencesController::class, 'index'])->name('preferences');
            Route::get('/branches', [SettingsController::class, 'branches'])->name('branches');
            Route::get('/document-quota', [DocumentQuotaController::class, 'usage'])->name('document-quota');

            Route::middleware('module.access:system')->group(function (): void {
                Route::put('/preferences/{group}', [TenantPreferencesController::class, 'update'])->name('preferences.update');
                Route::put('/tenant', [SettingsController::class, 'updateTenant'])->name('tenant.update');
                Route::post('/exchange-rates/sync', [SettingsController::class, 'syncExchangeRates'])->name('exchange-rates.sync');
                Route::get('/users', [SettingsController::class, 'users'])->name('users');
                Route::get('/roles', [SettingsController::class, 'roles'])->name('roles');
                Route::get('/audit-logs', [SettingsController::class, 'auditLogs'])->name('audit-logs');
                Route::get('/security/two-factor', [TwoFactorController::class, 'policy'])->name('security.two-factor');
                Route::put('/security/two-factor', [TwoFactorController::class, 'updatePolicy'])->name('security.two-factor.update');
                Route::post('/users/{userId}/two-factor/reset', [TwoFactorController::class, 'resetMember'])->whereUuid('userId')->name('users.two-factor.reset');
            });

            Route::middleware('module.access:integrations')->group(function (): void {
                Route::get('/document-templates', [DocumentTemplateController::class, 'index'])->name('document-templates.index');
                Route::put('/document-templates/{type}', [DocumentTemplateController::class, 'update'])->name('document-templates.update');
                Route::delete('/document-templates/{type}', [DocumentTemplateController::class, 'destroy'])->name('document-templates.destroy');
            });
        });
    });
});
