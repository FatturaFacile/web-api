<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SupplierPortal\Http\Controllers\BankChangeController;
use Modules\SupplierPortal\Http\Controllers\DocumentController;
use Modules\SupplierPortal\Http\Controllers\OnboardingController;
use Modules\SupplierPortal\Http\Controllers\QualificationController;
use Modules\SupplierPortal\Http\Controllers\RfqResponseController;
use Modules\SupplierPortal\Http\Controllers\RiskController;
use Modules\SupplierPortal\Http\Controllers\SummaryController;
use Modules\SupplierPortal\Http\Controllers\SupplierPortalController;
use Modules\SupplierPortal\Http\Middleware\AuthenticateSupplierPortal;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Internal side: supplier lifecycle for the purchasing and finance staff. module.access:supplier_portal decides who
| reads (GET) and who writes; the policy reserves approving, rejecting and blocking a supplier and deciding bank detail
| changes.
*/
Route::prefix('api/v1/supplier-portal')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:supplier_portal'])
    ->name('api.v1.supplier-portal.')
    ->where(['id' => $uuid, 'supplierId' => $uuid, 'rfqId' => $uuid])
    ->group(function (): void {
        Route::get('/summary', [SummaryController::class, 'show'])->name('summary');

        Route::get('/onboardings', [OnboardingController::class, 'index'])->name('onboardings.index');
        Route::post('/onboardings', [OnboardingController::class, 'store'])->name('onboardings.store');
        Route::get('/onboardings/{id}', [OnboardingController::class, 'show'])->name('onboardings.show');
        Route::post('/onboardings/{id}/portal-access', [OnboardingController::class, 'portalAccess'])->name('onboardings.portal-access');
        Route::post('/onboardings/{id}/request-documents', [OnboardingController::class, 'requestDocuments'])->name('onboardings.request-documents');
        Route::post('/onboardings/{id}/start-review', [OnboardingController::class, 'startReview'])->name('onboardings.start-review');
        Route::post('/onboardings/{id}/approve', [OnboardingController::class, 'approve'])->name('onboardings.approve');
        Route::post('/onboardings/{id}/reject', [OnboardingController::class, 'reject'])->name('onboardings.reject');
        Route::post('/onboardings/{id}/block', [OnboardingController::class, 'block'])->name('onboardings.block');

        Route::get('/document-requirements', [DocumentController::class, 'requirements'])->name('document-requirements.index');
        Route::post('/document-requirements', [DocumentController::class, 'storeRequirement'])->name('document-requirements.store');
        Route::post('/document-requirements/seed-defaults', [DocumentController::class, 'seedDefaults'])->name('document-requirements.seed-defaults');
        Route::put('/document-requirements/{id}', [DocumentController::class, 'updateRequirement'])->name('document-requirements.update');

        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('/documents/{id}/accept', [DocumentController::class, 'accept'])->name('documents.accept');
        Route::post('/documents/{id}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
        Route::get('/documents/{id}/download', [DocumentController::class, 'download'])->name('documents.download');

        Route::get('/qualification-criteria', [QualificationController::class, 'criteria'])->name('qualification-criteria.index');
        Route::post('/qualification-criteria', [QualificationController::class, 'storeCriterion'])->name('qualification-criteria.store');
        Route::put('/qualification-criteria/{id}', [QualificationController::class, 'updateCriterion'])->name('qualification-criteria.update');
        Route::get('/qualification-assessments', [QualificationController::class, 'assessments'])->name('qualification-assessments.index');
        Route::post('/qualification-assessments', [QualificationController::class, 'storeAssessment'])->name('qualification-assessments.store');
        Route::get('/qualification-assessments/{id}', [QualificationController::class, 'showAssessment'])->name('qualification-assessments.show');
        Route::put('/qualification-assessments/{id}/scores', [QualificationController::class, 'recordScores'])->name('qualification-assessments.scores');
        Route::post('/qualification-assessments/{id}/complete', [QualificationController::class, 'complete'])->name('qualification-assessments.complete');

        Route::get('/risk', [RiskController::class, 'index'])->name('risk.index');
        Route::post('/risk/recalculate', [RiskController::class, 'recalculate'])->name('risk.recalculate');
        Route::get('/suppliers/{supplierId}/risk', [RiskController::class, 'show'])->name('risk.show');
        Route::get('/checks', [RiskController::class, 'checks'])->name('checks.index');
        Route::post('/checks', [RiskController::class, 'storeCheck'])->name('checks.store');

        Route::get('/bank-changes', [BankChangeController::class, 'index'])->name('bank-changes.index');
        Route::post('/bank-changes', [BankChangeController::class, 'store'])->name('bank-changes.store');
        Route::get('/bank-changes/{id}', [BankChangeController::class, 'show'])->name('bank-changes.show');
        Route::post('/bank-changes/{id}/verify-callback', [BankChangeController::class, 'verifyCallback'])->name('bank-changes.verify-callback');
        Route::post('/bank-changes/{id}/approve', [BankChangeController::class, 'approve'])->name('bank-changes.approve');
        Route::post('/bank-changes/{id}/reject', [BankChangeController::class, 'reject'])->name('bank-changes.reject');
        Route::post('/bank-changes/{id}/cancel', [BankChangeController::class, 'cancel'])->name('bank-changes.cancel');

        Route::get('/rfq-responses', [RfqResponseController::class, 'index'])->name('rfq-responses.index');
        Route::get('/rfq-responses/{rfqId}/comparison', [RfqResponseController::class, 'comparison'])->name('rfq-responses.comparison');
    });

/*
| Supplier side: the personal token of the invitation mail (X-Supplier-Token) selects the tenant and the supplier.
*/
Route::prefix('api/v1/supplier-self-service')
    ->middleware(['api', 'locale', 'throttle:60,1', AuthenticateSupplierPortal::class])
    ->name('api.v1.supplier-self-service.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/me', [SupplierPortalController::class, 'me'])->name('me');
        Route::post('/registration', [SupplierPortalController::class, 'register'])->name('registration');
        Route::post('/documents', [SupplierPortalController::class, 'uploadDocument'])->name('documents.store');
        Route::post('/submit-for-review', [SupplierPortalController::class, 'submitForReview'])->name('submit-for-review');
        Route::get('/purchase-orders', [SupplierPortalController::class, 'purchaseOrders'])->name('purchase-orders.index');
        Route::get('/purchase-orders/{id}', [SupplierPortalController::class, 'purchaseOrder'])->name('purchase-orders.show');
        Route::get('/rfqs', [SupplierPortalController::class, 'rfqs'])->name('rfqs.index');
        Route::get('/rfqs/{id}', [SupplierPortalController::class, 'rfq'])->name('rfqs.show');
        Route::post('/rfqs/{id}/quote', [SupplierPortalController::class, 'submitQuote'])->name('rfqs.quote');
        Route::post('/rfqs/{id}/decline', [SupplierPortalController::class, 'decline'])->name('rfqs.decline');
        Route::get('/invoices', [SupplierPortalController::class, 'invoices'])->name('invoices.index');
        Route::get('/bank-changes', [SupplierPortalController::class, 'bankChanges'])->name('bank-changes.index');
        Route::post('/bank-changes', [SupplierPortalController::class, 'requestBankChange'])->name('bank-changes.store');
    });
