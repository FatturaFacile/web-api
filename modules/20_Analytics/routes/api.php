<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;
use Modules\Analytics\Http\Controllers\KpiController;
use Modules\Analytics\Http\Controllers\ReportDefinitionController;

Route::prefix('api/v1/analytics')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:analytics'])
    ->group(function (): void {
        Route::get('due-calendar', [AnalyticsController::class, 'dueCalendar'])->name('analytics.due-calendar');
        Route::get('revenue-comparison', [AnalyticsController::class, 'revenueComparison'])->name('analytics.revenue-comparison');
        Route::get('breakdown/{dimension}', [AnalyticsController::class, 'breakdown'])->whereIn('dimension', ['customer-type', 'product', 'purchases'])->name('analytics.breakdown');

        Route::get('kpis', [KpiController::class, 'index'])->name('analytics.kpis.index');
        Route::get('kpis-export', [KpiController::class, 'exportSummary'])->name('analytics.kpis.export');
        Route::get('kpis/{kpi}', [KpiController::class, 'show'])->name('analytics.kpis.show');
        Route::get('kpis/{kpi}/export', [KpiController::class, 'exportDetail'])->name('analytics.kpis.export-detail');

        Route::get('report-definitions', [ReportDefinitionController::class, 'index'])->name('analytics.report-definitions.index');
        Route::post('report-definitions', [ReportDefinitionController::class, 'store'])->name('analytics.report-definitions.store');
        Route::get('report-definitions/{id}', [ReportDefinitionController::class, 'show'])->whereUuid('id')->name('analytics.report-definitions.show');
        Route::put('report-definitions/{id}', [ReportDefinitionController::class, 'update'])->whereUuid('id')->name('analytics.report-definitions.update');
        Route::delete('report-definitions/{id}', [ReportDefinitionController::class, 'destroy'])->whereUuid('id')->name('analytics.report-definitions.destroy');
        Route::get('report-definitions/{id}/run', [ReportDefinitionController::class, 'run'])->whereUuid('id')->name('analytics.report-definitions.run');
        Route::get('report-definitions/{id}/export', [ReportDefinitionController::class, 'export'])->whereUuid('id')->name('analytics.report-definitions.export');
    });
