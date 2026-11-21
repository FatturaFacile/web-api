<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Quoting\Presentation\Http\Controllers\PublicProposalController;
use Modules\Quoting\Presentation\Http\Controllers\QuoteController;

// cpq_quotes: the sales team builds, issues, revises and converts quotes. Issuing a quote below its margin guardrail needs
// cpq_quotes_admin (managers), the same permission that may override it.
Route::prefix('api/v1/cpq')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:cpq_quotes')->group(function (): void {
        Route::get('quotes', [QuoteController::class, 'index'])->name('cpq.quotes.index');
        Route::get('quotes/{id}', [QuoteController::class, 'show'])->whereUuid('id')->name('cpq.quotes.show');
        Route::get('quotes/{id}/compare/{otherId}', [QuoteController::class, 'compare'])->whereUuid(['id', 'otherId'])->name('cpq.quotes.compare');
        Route::get('quotes/{id}/documents/{kind}', [QuoteController::class, 'document'])->whereUuid('id')->name('cpq.quotes.document');
        Route::post('quotes', [QuoteController::class, 'store'])->name('cpq.quotes.store');
        Route::put('quotes/{id}', [QuoteController::class, 'update'])->whereUuid('id')->name('cpq.quotes.update');
        Route::post('quotes/{id}/reorder', [QuoteController::class, 'reorder'])->whereUuid('id')->name('cpq.quotes.reorder');
        Route::post('quotes/{id}/issue', [QuoteController::class, 'issue'])->whereUuid('id')->name('cpq.quotes.issue');
        Route::post('quotes/{id}/revisions', [QuoteController::class, 'createRevision'])->whereUuid('id')->name('cpq.quotes.revisions');
        Route::post('quotes/{id}/convert-to-order', [QuoteController::class, 'convertToOrder'])->whereUuid('id')->name('cpq.quotes.convert');
    });

    Route::middleware('module.access:cpq_quotes_admin')->group(function (): void {
        Route::post('quotes/{id}/approve-and-issue', [QuoteController::class, 'approveAndIssue'])->whereUuid('id')->name('cpq.quotes.approve-and-issue');
    });
});

// Public proposal portal: no account, the unguessable token is the credential (optional PIN session, OTP or trust service
// provider signature). Rate limited per IP; the link refusals all answer the same 404.
Route::prefix('api/v1/public')->middleware(['api', 'throttle:60,1'])->group(function (): void {
    Route::get('proposals/{token}', [PublicProposalController::class, 'show'])->name('cpq.public.show');
    Route::get('proposals/{token}/documents/{kind}', [PublicProposalController::class, 'document'])->name('cpq.public.document');
    Route::post('proposals/{token}/verify-pin', [PublicProposalController::class, 'verifyPin'])->middleware('throttle:10,1')->name('cpq.public.pin');
    Route::put('proposals/{token}/selections', [PublicProposalController::class, 'selections'])->name('cpq.public.selections');
    Route::post('proposals/{token}/signature', [PublicProposalController::class, 'initiateSignature'])->middleware('throttle:10,1')->name('cpq.public.signature');
    Route::post('proposals/{token}/signature/complete', [PublicProposalController::class, 'completeSignature'])->middleware('throttle:10,1')->name('cpq.public.signature.complete');
    Route::post('proposals/{token}/reject', [PublicProposalController::class, 'reject'])->name('cpq.public.reject');
    Route::post('proposals/{token}/comments', [PublicProposalController::class, 'comment'])->middleware('throttle:20,1')->name('cpq.public.comments');
    Route::post('signature-webhooks', [PublicProposalController::class, 'webhook'])->name('cpq.public.webhook');
});
