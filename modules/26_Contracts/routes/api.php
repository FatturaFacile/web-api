<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Contracts\Http\Controllers\ContractDocumentController;
use Modules\Contracts\Http\Controllers\ContractLifecycleController;
use Modules\Contracts\Http\Controllers\ContractObligationController;
use Modules\Contracts\Http\Controllers\ContractPartyController;
use Modules\Contracts\Http\Controllers\ContractRegisterController;

Route::prefix('api/v1/contract-register')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:contracts'])
    ->group(function (): void {
        Route::get('/', [ContractRegisterController::class, 'index'])->name('contracts.index');
        Route::post('/', [ContractRegisterController::class, 'store'])->name('contracts.store');
        Route::get('alerts', [ContractRegisterController::class, 'alerts'])->name('contracts.alerts');
        Route::post('alerts/{id}/acknowledge', [ContractRegisterController::class, 'acknowledgeAlert'])->whereUuid('id')->name('contracts.alerts.acknowledge');
        Route::get('obligations', [ContractObligationController::class, 'index'])->name('contracts.obligations.index');
        Route::put('obligations/{obligationId}', [ContractObligationController::class, 'update'])->whereUuid('obligationId')->name('contracts.obligations.update');
        Route::delete('obligations/{obligationId}', [ContractObligationController::class, 'destroy'])->whereUuid('obligationId')->name('contracts.obligations.destroy');
        Route::post('obligations/{obligationId}/complete', [ContractObligationController::class, 'complete'])->whereUuid('obligationId')->name('contracts.obligations.complete');
        Route::post('obligations/{obligationId}/waive', [ContractObligationController::class, 'waive'])->whereUuid('obligationId')->name('contracts.obligations.waive');
        Route::get('{kind}/{id}', [ContractRegisterController::class, 'show'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.show');
        Route::put('{kind}/{id}/terms', [ContractRegisterController::class, 'updateTerms'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.terms');
        Route::post('{kind}/{id}/renew', [ContractRegisterController::class, 'renew'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.renew');
        Route::post('{kind}/{id}/notice', [ContractRegisterController::class, 'giveNotice'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.notice');

        Route::post('{kind}/{id}/transition', [ContractLifecycleController::class, 'transition'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.transition');
        Route::get('{kind}/{id}/history', [ContractLifecycleController::class, 'history'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.history');

        Route::get('{kind}/{id}/obligations', [ContractObligationController::class, 'forContract'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.obligations.contract');
        Route::post('{kind}/{id}/obligations', [ContractObligationController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.obligations.store');

        Route::get('{kind}/{id}/parties', [ContractPartyController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.parties.index');
        Route::post('{kind}/{id}/parties', [ContractPartyController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.parties.store');
        Route::delete('{kind}/{id}/parties/{partyId}', [ContractPartyController::class, 'destroy'])->whereIn('kind', ['customer', 'supplier'])->whereUuid(['id', 'partyId'])->name('contracts.parties.destroy');

        Route::get('{kind}/{id}/documents', [ContractDocumentController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.documents.index');
        Route::post('{kind}/{id}/documents', [ContractDocumentController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->whereUuid('id')->name('contracts.documents.store');
        Route::delete('{kind}/{id}/documents/{documentId}', [ContractDocumentController::class, 'destroy'])->whereIn('kind', ['customer', 'supplier'])->whereUuid(['id', 'documentId'])->name('contracts.documents.destroy');
    });

use Modules\Contracts\Http\Controllers\ClauseController;
use Modules\Contracts\Http\Controllers\ContractTemplateController;
use Modules\Contracts\Http\Controllers\DocumentVersionController;
use Modules\Contracts\Http\Controllers\NegotiationRoundController;
use Modules\Contracts\Http\Controllers\PublicSigningController;
use Modules\Contracts\Http\Controllers\SignatureRequestController;

/*
| Contract authoring: clause library, templates, redlining and e-signature. Same module.access:contracts rules as the
| register: every member reads, only the contract roles write.
*/
Route::prefix('api/v1/contract-authoring')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:contracts'])
    ->name('api.v1.contract-authoring.')
    ->whereUuid(['id', 'fallbackId'])
    ->group(function (): void {
        Route::get('clauses', [ClauseController::class, 'index'])->name('clauses.index');
        Route::post('clauses', [ClauseController::class, 'store'])->name('clauses.store');
        Route::get('clauses/{id}', [ClauseController::class, 'show'])->name('clauses.show');
        Route::put('clauses/{id}', [ClauseController::class, 'update'])->name('clauses.update');
        Route::post('clauses/{id}/versions', [ClauseController::class, 'addVersion'])->name('clauses.versions.store');
        Route::post('clauses/{id}/status', [ClauseController::class, 'transition'])->name('clauses.status');
        Route::post('clauses/{id}/fallbacks', [ClauseController::class, 'addFallback'])->name('clauses.fallbacks.store');
        Route::delete('clauses/{id}/fallbacks/{fallbackId}', [ClauseController::class, 'destroyFallback'])->name('clauses.fallbacks.destroy');

        Route::get('templates', [ContractTemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [ContractTemplateController::class, 'store'])->name('templates.store');
        Route::get('templates/{id}', [ContractTemplateController::class, 'show'])->name('templates.show');
        Route::put('templates/{id}', [ContractTemplateController::class, 'update'])->name('templates.update');
        Route::delete('templates/{id}', [ContractTemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('templates/{id}/preview', [ContractTemplateController::class, 'preview'])->name('templates.preview');

        Route::get('contracts/{kind}/{id}/versions', [DocumentVersionController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->name('versions.index');
        Route::post('contracts/{kind}/{id}/versions', [DocumentVersionController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->name('versions.store');
        Route::post('contracts/{kind}/{id}/versions/from-template', [DocumentVersionController::class, 'fromTemplate'])->whereIn('kind', ['customer', 'supplier'])->name('versions.from-template');
        Route::get('contracts/{kind}/{id}/versions/compare', [DocumentVersionController::class, 'compare'])->whereIn('kind', ['customer', 'supplier'])->name('versions.compare');
        Route::get('document-versions/{id}', [DocumentVersionController::class, 'show'])->name('versions.show');
        Route::get('document-versions/{id}/comments', [DocumentVersionController::class, 'comments'])->name('comments.index');
        Route::post('document-versions/{id}/comments', [DocumentVersionController::class, 'addComment'])->name('comments.store');
        Route::post('contract-comments/{id}/resolve', [DocumentVersionController::class, 'resolveComment'])->name('comments.resolve');

        Route::get('contracts/{kind}/{id}/rounds', [NegotiationRoundController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->name('rounds.index');
        Route::post('contracts/{kind}/{id}/rounds', [NegotiationRoundController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->name('rounds.store');
        Route::post('negotiation-rounds/{id}/close', [NegotiationRoundController::class, 'close'])->name('rounds.close');

        Route::get('signature-providers', [SignatureRequestController::class, 'providers'])->name('signature-providers.index');
        Route::get('contracts/{kind}/{id}/signature-requests', [SignatureRequestController::class, 'index'])->whereIn('kind', ['customer', 'supplier'])->name('signature-requests.index');
        Route::post('contracts/{kind}/{id}/signature-requests', [SignatureRequestController::class, 'store'])->whereIn('kind', ['customer', 'supplier'])->name('signature-requests.store');
        Route::get('signature-requests/{id}', [SignatureRequestController::class, 'show'])->name('signature-requests.show');
        Route::post('signature-requests/{id}/send', [SignatureRequestController::class, 'send'])->name('signature-requests.send');
        Route::post('signature-requests/{id}/sign', [SignatureRequestController::class, 'sign'])->name('signature-requests.sign');
        Route::post('signature-requests/{id}/decline', [SignatureRequestController::class, 'decline'])->name('signature-requests.decline');
        Route::post('signature-requests/{id}/cancel', [SignatureRequestController::class, 'cancel'])->name('signature-requests.cancel');
    });

/*
| Signing page without an account: the secret token of the link is the credential (stored hashed). Throttled by IP.
*/
Route::prefix('api/v1/public/contract-signing')
    ->middleware(['api', 'throttle:30,1'])
    ->name('api.v1.public.contract-signing.')
    ->group(function (): void {
        Route::get('{token}', [PublicSigningController::class, 'show'])->name('show');
        Route::post('{token}/sign', [PublicSigningController::class, 'sign'])->name('sign');
        Route::post('{token}/decline', [PublicSigningController::class, 'decline'])->name('decline');
    });
