<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DebtCollection\Http\Controllers\ConfigurationController;
use Modules\DebtCollection\Http\Controllers\DossierController;
use Modules\DebtCollection\Http\Controllers\ExclusionController;
use Modules\DebtCollection\Http\Controllers\NoticeController;
use Modules\DebtCollection\Http\Controllers\ProposalController;

/*
|--------------------------------------------------------------------------
| Dunning and debt collection (Mod 014, Mahnwesen / Issue #267)
|--------------------------------------------------------------------------
| Multi-level dunning proposal, late-payment interest (Art. 104 CO, D.Lgs. 231/2002), notices and bulk dispatch, Swiss
| Betreibung dossier, Italian messa in mora by PEC with receipts, exclusions, payment plans and the customer history.
*/
Route::prefix('api/v1/debt-collection')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:debt_collection'])
    ->whereUuid(['id', 'customerId'])
    ->group(function (): void {
        Route::get('lookups', [ProposalController::class, 'lookups'])->name('debt-collection.lookups');
        Route::get('dashboard', [ProposalController::class, 'dashboard'])->name('debt-collection.dashboard');
        Route::get('proposals', [ProposalController::class, 'index'])->name('debt-collection.proposals.index');
        Route::post('proposals/generate', [ProposalController::class, 'store'])->name('debt-collection.proposals.generate');
        Route::post('calculate', [ProposalController::class, 'calculate'])->name('debt-collection.calculate');

        Route::get('notices', [NoticeController::class, 'index'])->name('debt-collection.notices.index');
        Route::get('notices/{id}', [NoticeController::class, 'show'])->name('debt-collection.notices.show');
        Route::get('notices/{id}/pdf', [NoticeController::class, 'pdf'])->name('debt-collection.notices.pdf');
        Route::get('notices/{id}/export', [NoticeController::class, 'exportPackage'])->name('debt-collection.notices.export');
        Route::post('notices/{id}/send', [NoticeController::class, 'send'])->name('debt-collection.notices.send');
        Route::post('notices/{id}/cancel', [NoticeController::class, 'cancel'])->name('debt-collection.notices.cancel');
        Route::post('notices/{id}/pec-receipts', [NoticeController::class, 'recordReceipt'])->name('debt-collection.notices.receipts');

        Route::get('batches', [NoticeController::class, 'batches'])->name('debt-collection.batches.index');
        Route::post('batches', [NoticeController::class, 'storeBatch'])->name('debt-collection.batches.store');
        Route::get('batches/{id}', [NoticeController::class, 'showBatch'])->name('debt-collection.batches.show');
        Route::post('batches/{id}/retry', [NoticeController::class, 'retryBatch'])->name('debt-collection.batches.retry');

        Route::get('policies', [ConfigurationController::class, 'policies'])->name('debt-collection.policies.index');
        Route::post('policies', [ConfigurationController::class, 'storePolicy'])->name('debt-collection.policies.store');
        Route::get('policies/{id}', [ConfigurationController::class, 'showPolicy'])->name('debt-collection.policies.show');
        Route::put('policies/{id}', [ConfigurationController::class, 'updatePolicy'])->name('debt-collection.policies.update');
        Route::delete('policies/{id}', [ConfigurationController::class, 'destroyPolicy'])->name('debt-collection.policies.destroy');

        Route::get('interest-rates', [ConfigurationController::class, 'rates'])->name('debt-collection.rates.index');
        Route::post('interest-rates', [ConfigurationController::class, 'storeRate'])->name('debt-collection.rates.store');
        Route::put('interest-rates/{id}', [ConfigurationController::class, 'updateRate'])->name('debt-collection.rates.update');
        Route::delete('interest-rates/{id}', [ConfigurationController::class, 'destroyRate'])->name('debt-collection.rates.destroy');

        Route::get('templates', [ConfigurationController::class, 'templates'])->name('debt-collection.templates.index');
        Route::put('templates/{id}', [ConfigurationController::class, 'updateTemplate'])->name('debt-collection.templates.update');

        Route::get('enforcement-offices', [ConfigurationController::class, 'offices'])->name('debt-collection.offices.index');
        Route::post('enforcement-offices', [ConfigurationController::class, 'storeOffice'])->name('debt-collection.offices.store');
        Route::put('enforcement-offices/{id}', [ConfigurationController::class, 'updateOffice'])->name('debt-collection.offices.update');
        Route::delete('enforcement-offices/{id}', [ConfigurationController::class, 'destroyOffice'])->name('debt-collection.offices.destroy');

        Route::get('exclusions', [ExclusionController::class, 'index'])->name('debt-collection.exclusions.index');
        Route::post('exclusions', [ExclusionController::class, 'store'])->name('debt-collection.exclusions.store');
        Route::post('exclusions/{id}/release', [ExclusionController::class, 'release'])->name('debt-collection.exclusions.release');

        Route::get('payment-plans', [ExclusionController::class, 'plans'])->name('debt-collection.plans.index');
        Route::post('payment-plans', [ExclusionController::class, 'storePlan'])->name('debt-collection.plans.store');
        Route::post('payment-plans/{id}/{transition}', [ExclusionController::class, 'transition'])->whereIn('transition', ['complete', 'default', 'cancel'])->name('debt-collection.plans.transition');

        Route::get('dossiers', [DossierController::class, 'index'])->name('debt-collection.dossiers.index');
        Route::post('dossiers', [DossierController::class, 'store'])->name('debt-collection.dossiers.store');
        Route::get('dossiers/{id}', [DossierController::class, 'show'])->name('debt-collection.dossiers.show');
        Route::get('dossiers/{id}/pdf', [DossierController::class, 'pdf'])->name('debt-collection.dossiers.pdf');
        Route::get('dossiers/{id}/bundle', [DossierController::class, 'bundle'])->name('debt-collection.dossiers.bundle');
        Route::post('dossiers/{id}/submit', [DossierController::class, 'submit'])->name('debt-collection.dossiers.submit');
        Route::post('messa-in-mora', [DossierController::class, 'messaInMora'])->name('debt-collection.messa-in-mora');

        Route::get('customers/{customerId}/history', [DossierController::class, 'customerHistory'])->name('debt-collection.customers.history');
    });
