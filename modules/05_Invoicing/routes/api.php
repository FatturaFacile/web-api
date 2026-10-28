<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Invoicing\Http\Controllers\CreditNoteController;
use Modules\Invoicing\Http\Controllers\CustomerCreditController;
use Modules\Invoicing\Http\Controllers\CustomerLedgerController;
use Modules\Invoicing\Http\Controllers\DeliveryNoteController;
use Modules\Invoicing\Http\Controllers\InvoiceController;
use Modules\Invoicing\Http\Controllers\SalesOrderController;
use Modules\Invoicing\Http\Controllers\SalesQuoteController;

Route::prefix('api/v1/invoicing')->middleware(['api'])->group(function (): void {
    // Quotes (Order-To-Cash)
    Route::get('quotes', [SalesQuoteController::class, 'index'])->name('invoicing.quotes.index');
    Route::post('quotes', [SalesQuoteController::class, 'store'])->name('invoicing.quotes.store');
    Route::get('quotes/{id}', [SalesQuoteController::class, 'show'])->name('invoicing.quotes.show');
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
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('invoicing.invoices.show');
    Route::post('invoices/{id}/approve', [InvoiceController::class, 'approve'])->name('invoicing.invoices.approve');
    Route::post('invoices/{id}/post', [InvoiceController::class, 'post'])->name('invoicing.invoices.post');

    // Credit Notes
    Route::post('credit-notes', [CreditNoteController::class, 'store'])->name('invoicing.credit-notes.store');
    Route::post('credit-notes/{creditNoteId}/apply', [CreditNoteController::class, 'apply'])->name('invoicing.credit-notes.apply');

    // Credit Risk Management
    Route::get('customer-credit/{customerId}', [CustomerCreditController::class, 'show'])->name('invoicing.customer-credit.show');
    Route::post('customer-credit/limit', [CustomerCreditController::class, 'updateLimit'])->name('invoicing.customer-credit.update-limit');
    Route::post('customer-credit/holds/{holdId}/release', [CustomerCreditController::class, 'releaseHold'])->name('invoicing.customer-credit.release-hold');

    // Accounts Receivable Ledger & Aging
    Route::get('customer-ledger/{customerId}/statement', [CustomerLedgerController::class, 'statement'])->name('invoicing.customer-ledger.statement');
    Route::get('customer-ledger/{customerId}/aging', [CustomerLedgerController::class, 'aging'])->name('invoicing.customer-ledger.aging');
});
