<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\KpiDashboards\Presentation\Http\Controllers\KpiDashboardController;

// kpi_dashboards: any member opens the cockpits their role allows (the handlers enforce the role, entity and field rules of
// each KPI) and saves their own layouts. kpi_dashboards_admin: finance and managers configure alerts and refresh the cubes.
Route::prefix('api/v1/kpi-dashboards')->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])->group(function (): void {
    Route::middleware('module.access:kpi_dashboards')->group(function (): void {
        Route::get('cockpit', [KpiDashboardController::class, 'cockpit'])->name('kpi-dashboards.cockpit');
        Route::get('drill-down/{kpi}', [KpiDashboardController::class, 'drillDown'])->name('kpi-dashboards.drill-down');
        Route::get('catalog', [KpiDashboardController::class, 'catalog'])->name('kpi-dashboards.catalog');
        Route::post('layout', [KpiDashboardController::class, 'saveLayout'])->name('kpi-dashboards.layout');
    });

    Route::middleware('module.access:kpi_dashboards_admin')->group(function (): void {
        Route::post('cubes/refresh', [KpiDashboardController::class, 'refreshCubes'])->name('kpi-dashboards.refresh');
        Route::get('alerts', [KpiDashboardController::class, 'alerts'])->name('kpi-dashboards.alerts');
        Route::post('alerts', [KpiDashboardController::class, 'configureAlert'])->name('kpi-dashboards.alerts.configure');
        Route::post('alerts/{id}/acknowledge', [KpiDashboardController::class, 'acknowledgeAlert'])->whereUuid('id')->name('kpi-dashboards.alerts.acknowledge');
    });
});
