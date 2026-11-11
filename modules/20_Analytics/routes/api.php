<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;
use Modules\Analytics\Http\Controllers\DesignedReportController;
use Modules\Analytics\Http\Controllers\ExportDownloadController;
use Modules\Analytics\Http\Controllers\ExportScheduleController;
use Modules\Analytics\Http\Controllers\KpiController;
use Modules\Analytics\Http\Controllers\KpiSnapshotController;
use Modules\Analytics\Http\Controllers\ReportDefinitionController;
use Modules\Analytics\Http\Controllers\SemanticLayerController;

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

        Route::get('semantic/datasets', [SemanticLayerController::class, 'index'])->name('analytics.semantic.datasets.index');
        Route::get('semantic/datasets/{dataset}', [SemanticLayerController::class, 'show'])->where('dataset', '[a-z][a-z0-9_]*')->name('analytics.semantic.datasets.show');
        Route::post('designer/run', [SemanticLayerController::class, 'run'])->name('analytics.designer.run');
        Route::post('designer/export', [SemanticLayerController::class, 'export'])->name('analytics.designer.export');

        Route::get('designed-reports', [DesignedReportController::class, 'index'])->name('analytics.designed-reports.index');
        Route::post('designed-reports', [DesignedReportController::class, 'store'])->name('analytics.designed-reports.store');
        Route::get('designed-reports/{id}', [DesignedReportController::class, 'show'])->whereUuid('id')->name('analytics.designed-reports.show');
        Route::put('designed-reports/{id}', [DesignedReportController::class, 'update'])->whereUuid('id')->name('analytics.designed-reports.update');
        Route::delete('designed-reports/{id}', [DesignedReportController::class, 'destroy'])->whereUuid('id')->name('analytics.designed-reports.destroy');
        Route::get('designed-reports/{id}/run', [DesignedReportController::class, 'run'])->whereUuid('id')->name('analytics.designed-reports.run');
        Route::get('designed-reports/{id}/export', [DesignedReportController::class, 'export'])->whereUuid('id')->name('analytics.designed-reports.export');

        Route::get('export-schedules', [ExportScheduleController::class, 'index'])->name('analytics.export-schedules.index');
        Route::post('export-schedules', [ExportScheduleController::class, 'store'])->name('analytics.export-schedules.store');
        Route::get('export-schedules/{id}', [ExportScheduleController::class, 'show'])->whereUuid('id')->name('analytics.export-schedules.show');
        Route::put('export-schedules/{id}', [ExportScheduleController::class, 'update'])->whereUuid('id')->name('analytics.export-schedules.update');
        Route::delete('export-schedules/{id}', [ExportScheduleController::class, 'destroy'])->whereUuid('id')->name('analytics.export-schedules.destroy');

        Route::get('kpi-snapshots', [KpiSnapshotController::class, 'index'])->name('analytics.kpi-snapshots.index');
    });

// The signed, expiring link of a scheduled export: the signature is the credential, so no token and no tenant header. It lives
// outside the analytics prefix on purpose: every route under it is behind module.access.
Route::prefix('api/v1/report-downloads')
    ->middleware(['api', 'throttle:30,1', 'signed'])
    ->group(function (): void {
        Route::get('analytics/{run}', [ExportDownloadController::class, 'show'])->whereUuid('run')->name('analytics.exports.download');
    });
