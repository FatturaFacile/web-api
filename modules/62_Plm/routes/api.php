<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Plm\Http\Controllers\ChangeOrderController;
use Modules\Plm\Http\Controllers\PlmEngineeringController;
use Modules\Plm\Http\Controllers\RevisionController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Product lifecycle management (Mod 168). module.access:plm lets every member read (GET) and only the roles with write
| access of config/permissions.php change anything; the policy keeps the records inside the company.
*/
Route::prefix('api/v1/plm')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:plm'])
    ->name('api.v1.plm.')
    ->where(['id' => $uuid, 'itemId' => $uuid])
    ->group(function (): void {
        Route::get('/dictionary', [PlmEngineeringController::class, 'dictionary'])->name('dictionary');

        Route::get('/change-orders', [ChangeOrderController::class, 'index'])->name('change-orders.index');
        Route::post('/change-orders', [ChangeOrderController::class, 'store'])->name('change-orders.store');
        Route::get('/change-orders/{id}', [ChangeOrderController::class, 'show'])->name('change-orders.show');
        Route::put('/change-orders/{id}', [ChangeOrderController::class, 'update'])->name('change-orders.update');
        Route::delete('/change-orders/{id}', [ChangeOrderController::class, 'destroy'])->name('change-orders.destroy');
        Route::post('/change-orders/{id}/submit', [ChangeOrderController::class, 'submit'])->name('change-orders.submit');
        Route::post('/change-orders/{id}/decide', [ChangeOrderController::class, 'decide'])->name('change-orders.decide');
        Route::post('/change-orders/{id}/cancel', [ChangeOrderController::class, 'cancel'])->name('change-orders.cancel');
        Route::post('/change-orders/{id}/implement', [ChangeOrderController::class, 'implement'])->name('change-orders.implement');
        Route::post('/change-orders/{id}/advance-stage', [PlmEngineeringController::class, 'advanceStage'])->name('change-orders.advance-stage');
        Route::post('/change-orders/{id}/department-signoff', [PlmEngineeringController::class, 'departmentSignoff'])->name('change-orders.department-signoff');
        Route::post('/change-orders/{id}/execute-cutover', [PlmEngineeringController::class, 'executeCutover'])->name('change-orders.execute-cutover');

        Route::get('/revisions', [RevisionController::class, 'index'])->name('revisions.index');
        Route::post('/revisions', [RevisionController::class, 'store'])->name('revisions.store');
        Route::get('/revisions/{id}', [RevisionController::class, 'show'])->name('revisions.show');
        Route::put('/revisions/{id}', [RevisionController::class, 'update'])->name('revisions.update');
        Route::delete('/revisions/{id}', [RevisionController::class, 'destroy'])->name('revisions.destroy');
        Route::post('/revisions/{id}/release', [RevisionController::class, 'release'])->name('revisions.release');
        Route::post('/revisions/{id}/obsolete', [RevisionController::class, 'obsolete'])->name('revisions.obsolete');
        Route::get('/revisions/{id}/compliance', [PlmEngineeringController::class, 'getCompliance'])->name('revisions.compliance.show');
        Route::post('/revisions/{id}/compliance', [PlmEngineeringController::class, 'updateCompliance'])->name('revisions.compliance.update');
        Route::get('/revisions/{id}/viewer-manifest', [PlmEngineeringController::class, 'viewerManifest'])->name('revisions.viewer-manifest');

        Route::get('/cad-imports', [PlmEngineeringController::class, 'listCadImports'])->name('cad-imports.index');
        Route::post('/cad-imports', [PlmEngineeringController::class, 'importCad'])->name('cad-imports.store');
        Route::get('/cad-imports/{id}', [PlmEngineeringController::class, 'showCadImport'])->name('cad-imports.show');
        Route::post('/cad-imports/{id}/commit', [PlmEngineeringController::class, 'commitCadImport'])->name('cad-imports.commit');

        Route::get('/where-used/{itemId}', [PlmEngineeringController::class, 'whereUsedImpact'])->name('where-used.impact');
        Route::get('/bom-diff', [PlmEngineeringController::class, 'bomDiff'])->name('bom-diff');
    });
