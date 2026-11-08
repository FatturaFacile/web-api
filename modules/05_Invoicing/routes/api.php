<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Invoicing\Http\Controllers\BillingPlanController;
use Modules\Invoicing\Http\Controllers\CreditNoteController;
use Modules\Invoicing\Http\Controllers\CustomerCreditController;
use Modules\Invoicing\Http\Controllers\CustomerLedgerController;
use Modules\Invoicing\Http\Controllers\DebitNoteController;
use Modules\Invoicing\Http\Controllers\DeclarationOfIntentController;
use Modules\Invoicing\Http\Controllers\DeliveryNoteController;
use Modules\Invoicing\Http\Controllers\InvoiceController;
use Modules\Invoicing\Http\Controllers\OrderBillingController;
use Modules\Invoicing\Http\Controllers\SalesOrderController;
use Modules\Invoicing\Http\Controllers\SalesQuoteController;
use Modules\Invoicing\Http\Controllers\SalesReportController;

Route::prefix('api/v1/invoicing')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:invoicing'])->group(function (): void {
    // Quotes (Order-To-Cash)
    Route::get('quotes', [SalesQuoteController::class, 'index'])->name('invoicing.quotes.index');
    Route::post('quotes', [SalesQuoteController::class, 'store'])->name('invoicing.quotes.store');
    Route::get('quotes/{id}', [SalesQuoteController::class, 'show'])->name('invoicing.quotes.show');
    Route::post('quotes/{id}/submit-for-approval', [SalesQuoteController::class, 'submitForApproval'])->name('invoicing.quotes.submit-for-approval');
    Route::post('quotes/{id}/approve', [SalesQuoteController::class, 'approve'])->name('invoicing.quotes.approve');
    Route::post('quotes/{id}/reject', [SalesQuoteController::class, 'reject'])->name('invoicing.quotes.reject');
    Route::post('quotes/{id}/accept', [SalesQuoteController::class, 'accept'])->name('invoicing.quotes.accept');
    Route::post('quotes/{id}/convert', [SalesQuoteController::class, 'convert'])->name('invoicing.quotes.convert');

    // Orders (Order-To-Cash)
    Route::get('orders', [SalesOrderController::class, 'index'])->name('invoicing.orders.index');
    Route::post('orders', [SalesOrderController::class, 'store'])->name('invoicing.orders.store');
    Route::get('orders/{id}', [SalesOrderController::class, 'show'])->name('invoicing.orders.show');
    Route::post('orders/{id}/approve', [SalesOrderController::class, 'approve'])->name('invoicing.orders.approve');
    Route::post('orders/{id}/reserve', [SalesOrderController::class, 'reserve'])->name('invoicing.orders.reserve');
    Route::post('orders/{id}/cancel', [SalesOrderController::class, 'cancel'])->name('invoicing.orders.cancel');

    // Delivery Notes / Fulfillment
    Route::get('delivery-notes', [DeliveryNoteController::class, 'index'])->name('invoicing.delivery-notes.index');
    Route::post('delivery-notes', [DeliveryNoteController::class, 'store'])->name('invoicing.delivery-notes.store');
    Route::get('delivery-notes/{id}', [DeliveryNoteController::class, 'show'])->name('invoicing.delivery-notes.show');
    Route::post('delivery-notes/{id}/dispatch', [DeliveryNoteController::class, 'dispatch'])->name('invoicing.delivery-notes.dispatch');
    Route::post('delivery-notes/{id}/confirm', [DeliveryNoteController::class, 'confirm'])->name('invoicing.delivery-notes.confirm');

    // Invoices & Billing Engine
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoicing.invoices.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoicing.invoices.store');
    Route::post('invoices/from-order/{orderId}', [InvoiceController::class, 'fromOrder'])->name('invoicing.invoices.from-order');
    Route::post('invoices/from-delivery/{deliveryNoteId}', [InvoiceController::class, 'fromDeliveryNote'])->name('invoicing.invoices.from-delivery');
    Route::post('invoices/deferred', [OrderBillingController::class, 'deferred'])->name('invoicing.invoices.deferred');
    Route::post('invoices/deferred-run', [OrderBillingController::class, 'deferredRun'])->name('invoicing.invoices.deferred-run');
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('invoicing.invoices.show');
    Route::get('invoices/{id}/installments', [SalesReportController::class, 'installments'])->whereUuid('id')->name('invoicing.invoices.installments');
    Route::post('invoices/{id}/approve', [InvoiceController::class, 'approve'])->name('invoicing.invoices.approve');
    Route::post('invoices/{id}/post', [InvoiceController::class, 'post'])->name('invoicing.invoices.post');

    // Order billing: down payments and billing plans (milestone billing)
    Route::post('orders/{orderId}/down-payment-invoices', [OrderBillingController::class, 'downPayment'])->whereUuid('orderId')->name('invoicing.orders.down-payment-invoices');
    Route::get('billing-plans', [BillingPlanController::class, 'index'])->name('invoicing.billing-plans.index');
    Route::post('billing-plans', [BillingPlanController::class, 'store'])->name('invoicing.billing-plans.store');
    Route::post('billing-plans/run', [BillingPlanController::class, 'run'])->name('invoicing.billing-plans.run');
    Route::get('billing-plans/{id}', [BillingPlanController::class, 'show'])->whereUuid('id')->name('invoicing.billing-plans.show');
    Route::post('billing-plans/{id}/items/{itemId}/invoice', [BillingPlanController::class, 'invoiceItem'])->whereUuid(['id', 'itemId'])->name('invoicing.billing-plans.items.invoice');
    Route::post('billing-plans/{id}/cancel', [BillingPlanController::class, 'cancel'])->whereUuid('id')->name('invoicing.billing-plans.cancel');

    // Credit and debit notes
    Route::post('credit-notes', [CreditNoteController::class, 'store'])->name('invoicing.credit-notes.store');
    Route::post('credit-notes/{creditNoteId}/apply', [CreditNoteController::class, 'apply'])->name('invoicing.credit-notes.apply');
    Route::post('debit-notes', [DebitNoteController::class, 'store'])->name('invoicing.debit-notes.store');

    // Sales reports (issued documents only)
    Route::get('reports/sales-register', [SalesReportController::class, 'salesRegister'])->name('invoicing.reports.sales-register');
    Route::get('reports/receivables-aging', [SalesReportController::class, 'receivablesAging'])->name('invoicing.reports.receivables-aging');

    // Declarations of Intent (Lettere di intento)
    Route::get('declarations-of-intent', [DeclarationOfIntentController::class, 'index'])->name('invoicing.declarations-of-intent.index');
    Route::post('declarations-of-intent', [DeclarationOfIntentController::class, 'store'])->name('invoicing.declarations-of-intent.store');
    Route::get('declarations-of-intent/summary', [DeclarationOfIntentController::class, 'summary'])->name('invoicing.declarations-of-intent.summary');
    Route::get('declarations-of-intent/{id}', [DeclarationOfIntentController::class, 'show'])->whereUuid('id')->name('invoicing.declarations-of-intent.show');
    Route::put('declarations-of-intent/{id}', [DeclarationOfIntentController::class, 'update'])->whereUuid('id')->name('invoicing.declarations-of-intent.update');
    Route::delete('declarations-of-intent/{id}', [DeclarationOfIntentController::class, 'destroy'])->whereUuid('id')->name('invoicing.declarations-of-intent.destroy');

    // Credit Risk Management
    Route::get('customer-credit/{customerId}', [CustomerCreditController::class, 'show'])->name('invoicing.customer-credit.show');
    Route::post('customer-credit/limit', [CustomerCreditController::class, 'updateLimit'])->name('invoicing.customer-credit.update-limit');
    Route::post('customer-credit/holds/{holdId}/release', [CustomerCreditController::class, 'releaseHold'])->name('invoicing.customer-credit.release-hold');

    // Accounts Receivable Ledger & Aging
    Route::get('customer-ledger/{customerId}/statement', [CustomerLedgerController::class, 'statement'])->name('invoicing.customer-ledger.statement');
    Route::get('customer-ledger/{customerId}/aging', [CustomerLedgerController::class, 'aging'])->name('invoicing.customer-ledger.aging');
});
