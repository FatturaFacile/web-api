<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Customs\Http\Controllers\AssessmentDecisionController;
use Modules\Customs\Http\Controllers\ConsolidationController;
use Modules\Customs\Http\Controllers\CustomsLedgerController;
use Modules\Customs\Http\Controllers\CustomsPortalController;
use Modules\Customs\Http\Controllers\CustomsSettingsController;
use Modules\Customs\Http\Controllers\DeclarationController;
use Modules\Customs\Http\Controllers\DossierController;
use Modules\Customs\Http\Controllers\MasterDataController;
use Modules\Customs\Http\Controllers\QuoteController;
use Modules\Customs\Http\Controllers\ShipmentRequestController;
use Modules\Customs\Http\Controllers\TariffGridController;

Route::prefix('api/v1/customs')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:customs'])
    ->name('api.v1.customs.')
    ->where(['id' => '[0-9a-fA-F-]{36}', 'itemId' => '[0-9a-fA-F-]{36}', 'rateId' => '[0-9a-fA-F-]{36}', 'dossierId' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::get('/settings', [CustomsSettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [CustomsSettingsController::class, 'update'])->name('settings.update');

        // Master data
        Route::get('/master-data/{type}', [MasterDataController::class, 'index'])->name('master-data.index');
        Route::post('/master-data/{type}', [MasterDataController::class, 'store'])->name('master-data.store');
        Route::put('/master-data/{type}/{id}', [MasterDataController::class, 'update'])->name('master-data.update');
        Route::delete('/master-data/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master-data.destroy');

        // Declarations
        Route::get('/tariff-history', [DeclarationController::class, 'tariffHistory'])->name('tariff-history');
        Route::get('/declarations', [DeclarationController::class, 'index'])->name('declarations.index');
        Route::post('/declarations', [DeclarationController::class, 'store'])->name('declarations.store');
        Route::post('/declarations/from-delivery-note', [DeclarationController::class, 'fromDeliveryNote'])->name('declarations.from-delivery-note');
        Route::get('/declarations/{id}', [DeclarationController::class, 'show'])->name('declarations.show');
        Route::put('/declarations/{id}', [DeclarationController::class, 'update'])->name('declarations.update');
        Route::delete('/declarations/{id}', [DeclarationController::class, 'destroy'])->name('declarations.destroy');
        Route::post('/declarations/{id}/validate', [DeclarationController::class, 'validateDeclaration'])->name('declarations.validate');
        Route::post('/declarations/{id}/submit', [DeclarationController::class, 'submit'])->name('declarations.submit');
        Route::post('/declarations/{id}/cancel', [DeclarationController::class, 'cancel'])->name('declarations.cancel');
        Route::post('/declarations/{id}/items/reuse/{itemId}', [DeclarationController::class, 'reuseItem'])->name('declarations.items.reuse');
        Route::get('/declarations/{id}/message', [DeclarationController::class, 'message'])->name('declarations.message');
        Route::get('/declarations/{id}/pdf', [DeclarationController::class, 'pdf'])->name('declarations.pdf');

        // Assessment decisions
        Route::get('/assessment-decisions', [AssessmentDecisionController::class, 'index'])->name('assessments.index');
        Route::post('/assessment-decisions/upload', [AssessmentDecisionController::class, 'upload'])->name('assessments.upload');
        Route::post('/assessment-decisions/retrieve', [AssessmentDecisionController::class, 'retrieve'])->name('assessments.retrieve');
        Route::get('/assessment-decisions/control-list', [AssessmentDecisionController::class, 'controlList'])->name('assessments.control-list');
        Route::get('/assessment-decisions/statistics', [AssessmentDecisionController::class, 'statistics'])->name('assessments.statistics');
        Route::get('/assessment-decisions/retrieval-runs', [AssessmentDecisionController::class, 'retrievalRuns'])->name('assessments.retrieval-runs');
        Route::get('/assessment-decisions/{id}', [AssessmentDecisionController::class, 'show'])->name('assessments.show');
        Route::get('/assessment-decisions/{id}/xml', [AssessmentDecisionController::class, 'xml'])->name('assessments.xml');
        Route::get('/assessment-decisions/{id}/pdf', [AssessmentDecisionController::class, 'pdf'])->name('assessments.pdf');
        Route::get('/assessment-decisions/{id}/integrity', [AssessmentDecisionController::class, 'integrity'])->name('assessments.integrity');

        // Ledger
        Route::post('/assessment-decisions/{id}/post', [CustomsLedgerController::class, 'post'])->name('assessments.post');
        Route::get('/ledger/reconciliation', [CustomsLedgerController::class, 'reconciliation'])->name('ledger.reconciliation');

        // Tariff grids and discounts
        Route::get('/tariff-grids', [TariffGridController::class, 'index'])->name('tariff-grids.index');
        Route::post('/tariff-grids', [TariffGridController::class, 'store'])->name('tariff-grids.store');
        Route::get('/tariff-grids/{id}', [TariffGridController::class, 'show'])->name('tariff-grids.show');
        Route::put('/tariff-grids/{id}', [TariffGridController::class, 'update'])->name('tariff-grids.update');
        Route::delete('/tariff-grids/{id}', [TariffGridController::class, 'destroy'])->name('tariff-grids.destroy');
        Route::post('/tariff-grids/{id}/rates', [TariffGridController::class, 'storeRate'])->name('tariff-grids.rates.store');
        Route::delete('/tariff-grids/{id}/rates/{rateId}', [TariffGridController::class, 'destroyRate'])->name('tariff-grids.rates.destroy');
        Route::get('/service-discounts', [TariffGridController::class, 'discounts'])->name('service-discounts.index');
        Route::put('/service-discounts', [TariffGridController::class, 'saveDiscount'])->name('service-discounts.save');

        // Dossiers
        Route::get('/dossiers', [DossierController::class, 'index'])->name('dossiers.index');
        Route::post('/dossiers', [DossierController::class, 'store'])->name('dossiers.store');
        Route::get('/dossiers/monthly-summary', [DossierController::class, 'monthlySummary'])->name('dossiers.monthly-summary');
        Route::get('/dossiers/{id}', [DossierController::class, 'show'])->name('dossiers.show');
        Route::put('/dossiers/{id}', [DossierController::class, 'update'])->name('dossiers.update');
        Route::post('/dossiers/{id}/charges', [DossierController::class, 'addCharge'])->name('dossiers.charges.store');
        Route::post('/dossiers/{id}/import-assessments', [DossierController::class, 'importAssessments'])->name('dossiers.import-assessments');
        Route::get('/dossiers/{id}/profitability', [DossierController::class, 'profitability'])->name('dossiers.profitability');
        Route::get('/dossiers/{id}/assessment-check', [DossierController::class, 'assessmentCheck'])->name('dossiers.assessment-check');
        Route::post('/dossiers/{id}/invoice', [DossierController::class, 'invoice'])->name('dossiers.invoice');
        Route::post('/dossiers/{id}/documents', [DossierController::class, 'storeDocument'])->name('dossiers.documents.store');
        Route::post('/dossiers/{id}/deliveries', [DossierController::class, 'addDelivery'])->name('dossiers.deliveries.store');
        Route::get('/dossiers/{id}/quotes', [QuoteController::class, 'index'])->name('dossiers.quotes.index');
        Route::post('/dossiers/{id}/quotes/from-grid', [QuoteController::class, 'fromGrid'])->name('dossiers.quotes.from-grid');
        Route::post('/dossiers/{id}/quotes/spot', [QuoteController::class, 'spot'])->name('dossiers.quotes.spot');
        Route::post('/quotes/{id}/status', [QuoteController::class, 'status'])->name('quotes.status');

        // Consolidations (groupage)
        Route::get('/consolidations', [ConsolidationController::class, 'index'])->name('consolidations.index');
        Route::post('/consolidations', [ConsolidationController::class, 'store'])->name('consolidations.store');
        Route::post('/consolidations/{id}/dossiers/{dossierId}', [ConsolidationController::class, 'attach'])->name('consolidations.attach');
        Route::post('/consolidations/{id}/allocate', [ConsolidationController::class, 'allocate'])->name('consolidations.allocate');

        // Shipment requests from the customer portal
        Route::get('/shipment-requests', [ShipmentRequestController::class, 'index'])->name('shipment-requests.index');
        Route::post('/shipment-requests/{id}/accept', [ShipmentRequestController::class, 'accept'])->name('shipment-requests.accept');
        Route::post('/shipment-requests/{id}/refuse', [ShipmentRequestController::class, 'refuse'])->name('shipment-requests.refuse');
    });

// The token of the magic link is the credential, as for the rest of the customer portal.
Route::prefix('api/v1/portal/customs/{token}')
    ->middleware(['api', 'throttle:60,1'])
    ->name('api.v1.portal.customs.')
    ->group(function (): void {
        Route::get('/dossiers', [CustomsPortalController::class, 'dossiers'])->name('dossiers');
        Route::get('/shipment-requests', [CustomsPortalController::class, 'requests'])->name('shipment-requests.index');
        Route::post('/shipment-requests', [CustomsPortalController::class, 'storeRequest'])->name('shipment-requests.store');
    });
