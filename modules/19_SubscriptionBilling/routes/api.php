<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SubscriptionBilling\Http\Controllers\DunningController;
use Modules\SubscriptionBilling\Http\Controllers\MetricsController;
use Modules\SubscriptionBilling\Http\Controllers\PlanController;
use Modules\SubscriptionBilling\Http\Controllers\PortalSubscriptionController;
use Modules\SubscriptionBilling\Http\Controllers\RevenueRecognitionController;
use Modules\SubscriptionBilling\Http\Controllers\SubscriptionController;
use Modules\SubscriptionBilling\Http\Controllers\UsageBillingController;

// Catalog, usage, billing runs, revenue recognition, metrics and dunning of the subscription engine. Every write route
// needs the write access of the subscriptions module (read-only roles get 403).
Route::prefix('api/v1/subscriptions')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:subscriptions'])
    ->group(function (): void {
        Route::get('plans', [PlanController::class, 'index'])->name('subscriptions.plans.index');
        Route::post('plans', [PlanController::class, 'store'])->name('subscriptions.plans.store');
        Route::get('plans/{id}', [PlanController::class, 'show'])->whereUuid('id')->name('subscriptions.plans.show');
        Route::put('plans/{id}', [PlanController::class, 'update'])->whereUuid('id')->name('subscriptions.plans.update');

        Route::post('usage', [UsageBillingController::class, 'recordUsage'])->name('subscriptions.usage.record');
        Route::get('usage', [UsageBillingController::class, 'listUsageEvents'])->name('subscriptions.usage.index');
        Route::get('billing-runs', [UsageBillingController::class, 'listBillingRuns'])->name('subscriptions.billing-runs.index');
        Route::post('billing-runs', [UsageBillingController::class, 'executeBillingRun'])->name('subscriptions.billing-runs.execute');
        Route::get('billing-runs/{id}', [UsageBillingController::class, 'showBillingRun'])->whereUuid('id')->name('subscriptions.billing-runs.show');

        Route::get('revenue-contracts', [RevenueRecognitionController::class, 'listContracts'])->name('subscriptions.revenue-contracts.index');
        Route::get('revenue-contracts/{id}', [RevenueRecognitionController::class, 'showContract'])->whereUuid('id')->name('subscriptions.revenue-contracts.show');
        Route::post('revenue-recognition/recognize', [RevenueRecognitionController::class, 'recognizeBatch'])->name('subscriptions.revenue-recognition.recognize');

        Route::get('metrics', [MetricsController::class, 'metrics'])->name('subscriptions.metrics');
        Route::get('forecast', [MetricsController::class, 'forecast'])->name('subscriptions.forecast');

        Route::get('dunning-policy', [DunningController::class, 'show'])->name('subscriptions.dunning-policy.show');
        Route::put('dunning-policy', [DunningController::class, 'update'])->name('subscriptions.dunning-policy.update');
        Route::post('dunning/run', [DunningController::class, 'run'])->name('subscriptions.dunning.run');
    });

// Contracts. The bare /api/v1/subscriptions/{id}/... paths are the deprecated legacy API (routes/api.php), served by
// the same engine.
Route::prefix('api/v1/sub')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:subscriptions'])
    ->group(function (): void {
        Route::get('contracts', [SubscriptionController::class, 'index'])->name('sub.contracts.index');
        Route::post('contracts', [SubscriptionController::class, 'store'])->name('sub.contracts.store');

        Route::prefix('contracts/{id}')->whereUuid('id')->group(function (): void {
            Route::get('/', [SubscriptionController::class, 'show'])->name('sub.contracts.show');
            Route::post('cancel', [SubscriptionController::class, 'cancel'])->name('sub.contracts.cancel');
            Route::post('upgrade', [SubscriptionController::class, 'upgrade'])->name('sub.contracts.upgrade');
            Route::post('downgrade', [SubscriptionController::class, 'downgrade'])->name('sub.contracts.downgrade');
            Route::post('quantity', [SubscriptionController::class, 'quantity'])->name('sub.contracts.quantity');
            Route::get('proration-preview', [SubscriptionController::class, 'prorationPreview'])->name('sub.contracts.proration-preview');
            Route::post('activate', [SubscriptionController::class, 'activate'])->name('sub.contracts.activate');
            Route::post('suspend', [SubscriptionController::class, 'suspend'])->name('sub.contracts.suspend');
            Route::post('pause', [SubscriptionController::class, 'pause'])->name('sub.contracts.pause');
            Route::post('reactivate', [SubscriptionController::class, 'reactivate'])->name('sub.contracts.reactivate');
            Route::post('bill', [SubscriptionController::class, 'bill'])->name('sub.contracts.bill');
            Route::get('invoices', [SubscriptionController::class, 'invoices'])->name('sub.contracts.invoices');
            Route::post('collection-failures', [SubscriptionController::class, 'collectionFailure'])->name('sub.contracts.collection-failures');
            Route::get('dunning', [SubscriptionController::class, 'dunning'])->name('sub.contracts.dunning');
        });
    });

// Customer portal (magic link, no login): read-only.
Route::prefix('api/v1/portal')
    ->middleware(['api', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('subscriptions/{token}', [PortalSubscriptionController::class, 'index'])->name('portal.subscriptions.list');
    });
