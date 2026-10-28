<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\CustomerPortalController;
use Illuminate\Support\Facades\Route;

// Redirect root API traffic to public website
Route::get('/', function () {
    return redirect()->away('https://www.fatturafacile.eu', 301);
});

// Direct OpenAPI spec file routes for Swagger UI / tooling
Route::get('/docs/api-docs.json', function () {
    $path = storage_path('api-docs/api-docs.json');
    abort_unless(file_exists($path), 404);

    return response()->file($path, ['Content-Type' => 'application/json']);
});

Route::get('/docs/api-docs.yaml', function () {
    $path = storage_path('api-docs/api-docs.yaml');
    abort_unless(file_exists($path), 404);

    return response()->file($path, ['Content-Type' => 'application/x-yaml']);
});

// Module 09: Customer Portal Magic Link Routes
Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::get('/access/{token}', [CustomerPortalController::class, 'access'])->name('access');

    // Quotes
    Route::get('/quotes/{token}', [CustomerPortalController::class, 'showQuote'])->name('quotes.show');
    Route::post('/quotes/{token}/approve', [CustomerPortalController::class, 'approveQuote'])->name('quotes.approve');
    Route::post('/quotes/{token}/reject', [CustomerPortalController::class, 'rejectQuote'])->name('quotes.reject');
    Route::get('/quotes/{token}/pdf', [CustomerPortalController::class, 'downloadQuotePdf'])->name('quotes.pdf');

    // Invoices
    Route::get('/invoices/{token}', [CustomerPortalController::class, 'listInvoices'])->name('invoices.list');
    Route::get('/invoices/{token}/{id}/pdf', [CustomerPortalController::class, 'downloadInvoicePdf'])->name('invoices.pdf');

    // Service Reports
    Route::get('/service-reports/{token}', [CustomerPortalController::class, 'listServiceReports'])->name('service-reports.list');
    Route::get('/service-reports/{token}/{id}/pdf', [CustomerPortalController::class, 'downloadServiceReportPdf'])->name('service-reports.pdf');

    // Tickets
    Route::get('/tickets/{token}', [CustomerPortalController::class, 'listTickets'])->name('tickets.list');
    Route::post('/tickets/{token}/reply/{ticketId}', [CustomerPortalController::class, 'replyTicket'])->name('tickets.reply');

    // Appointments
    Route::get('/appointments/{token}', [CustomerPortalController::class, 'listAppointments'])->name('appointments.list');

    // Attachments
    Route::get('/attachments/{token}/{attachment}', [CustomerPortalController::class, 'downloadAttachment'])->name('attachments.download');
});
