<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;

Route::prefix('api/v1/analytics')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:analytics'])
    ->group(function (): void {
        Route::get('due-calendar', [AnalyticsController::class, 'dueCalendar'])->name('analytics.due-calendar');
        Route::get('revenue-comparison', [AnalyticsController::class, 'revenueComparison'])->name('analytics.revenue-comparison');
        Route::get('breakdown/{dimension}', [AnalyticsController::class, 'breakdown'])->whereIn('dimension', ['customer-type', 'product', 'purchases'])->name('analytics.breakdown');
    });
