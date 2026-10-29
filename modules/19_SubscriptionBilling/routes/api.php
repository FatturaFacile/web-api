<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SubscriptionBilling\Http\Controllers\PlanController;
use Modules\SubscriptionBilling\Http\Controllers\RevenueRecognitionController;
use Modules\SubscriptionBilling\Http\Controllers\SubscriptionController;
use Modules\SubscriptionBilling\Http\Controllers\UsageBillingController;

Route::prefix('api/v1/subscriptions')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // Plans Catalog
        Route::get('plans', [PlanController::class, 'index'])->name('subscriptions.plans.index');
        Route::post('plans', [PlanController::class, 'store'])->name('subscriptions.plans.store');
        Route::get('plans/{id}', [PlanController::class, 'show'])->name('subscriptions.plans.show');

        // Usage Metering & Billing Runs
        Route::post('usage', [UsageBillingController::class, 'recordUsage'])->name('subscriptions.usage.record');
        Route::get('usage', [UsageBillingController::class, 'listUsageEvents'])->name('subscriptions.usage.index');
        Route::post('billing-runs', [UsageBillingController::class, 'executeBillingRun'])->name('subscriptions.billing-runs.execute');

        // Revenue Recognition (IFRS 15 / ASC 606)
        Route::get('revenue-contracts', [RevenueRecognitionController::class, 'listContracts'])->name('subscriptions.revenue-contracts.index');
        Route::get('revenue-contracts/{id}', [RevenueRecognitionController::class, 'showContract'])->name('subscriptions.revenue-contracts.show');
        Route::post('revenue-recognition/recognize', [RevenueRecognitionController::class, 'recognizeBatch'])->name('subscriptions.revenue-recognition.recognize');

        // Enterprise Subscription Lifecycle Actions
        Route::post('{id}/activate', [SubscriptionController::class, 'activate'])->name('subscriptions.enterprise.activate');
        Route::post('{id}/suspend', [SubscriptionController::class, 'suspend'])->name('subscriptions.enterprise.suspend');
        Route::post('{id}/reactivate', [SubscriptionController::class, 'reactivate'])->name('subscriptions.enterprise.reactivate');
        Route::post('{id}/upgrade', [SubscriptionController::class, 'upgrade'])->name('subscriptions.enterprise.upgrade');
        Route::post('{id}/downgrade', [SubscriptionController::class, 'downgrade'])->name('subscriptions.enterprise.downgrade');
    });

Route::prefix('api/v1/sub')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('contracts', [SubscriptionController::class, 'index'])->name('sub.contracts.index');
        Route::post('contracts', [SubscriptionController::class, 'store'])->name('sub.contracts.store');
        Route::get('contracts/{id}', [SubscriptionController::class, 'show'])->name('sub.contracts.show');
        Route::post('contracts/{id}/cancel', [SubscriptionController::class, 'cancel'])->name('sub.contracts.cancel');
        Route::post('contracts/{id}/upgrade', [SubscriptionController::class, 'upgrade'])->name('sub.contracts.upgrade');
        Route::post('contracts/{id}/downgrade', [SubscriptionController::class, 'downgrade'])->name('sub.contracts.downgrade');
        Route::post('contracts/{id}/activate', [SubscriptionController::class, 'activate'])->name('sub.contracts.activate');
        Route::post('contracts/{id}/suspend', [SubscriptionController::class, 'suspend'])->name('sub.contracts.suspend');
        Route::post('contracts/{id}/reactivate', [SubscriptionController::class, 'reactivate'])->name('sub.contracts.reactivate');
    });
