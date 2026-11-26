<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DataMigration\Http\Controllers\DataMigrationMetadataController;
use Modules\DataMigration\Http\Controllers\MigrationJobController;
use Modules\DataMigration\Http\Controllers\MigrationTemplateController;

/*
|--------------------------------------------------------------------------
| Guided Data Migration & Import/Export Wizard (Mod 104 / Issue #253)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1/data-migration')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:data_migration'])
    ->group(function (): void {
        Route::get('metadata', [DataMigrationMetadataController::class, 'index'])->name('data-migration.metadata');

        Route::get('templates', [MigrationTemplateController::class, 'index'])->name('data-migration.templates.index');
        Route::post('templates', [MigrationTemplateController::class, 'store'])->name('data-migration.templates.store');
        Route::get('templates/download', [MigrationTemplateController::class, 'download'])->name('data-migration.templates.download');

        Route::get('jobs', [MigrationJobController::class, 'index'])->name('data-migration.jobs.index');
        Route::post('jobs', [MigrationJobController::class, 'store'])->name('data-migration.jobs.store');
        Route::get('jobs/{id}', [MigrationJobController::class, 'show'])->whereUuid('id')->name('data-migration.jobs.show');
        Route::post('jobs/{id}/auto-map', [MigrationJobController::class, 'autoMap'])->whereUuid('id')->name('data-migration.jobs.auto-map');
        Route::post('jobs/{id}/dry-run', [MigrationJobController::class, 'dryRun'])->whereUuid('id')->name('data-migration.jobs.dry-run');
        Route::get('jobs/{id}/dry-run-report', [MigrationJobController::class, 'dryRunReport'])->whereUuid('id')->name('data-migration.jobs.dry-run-report');
        Route::patch('jobs/{id}/staging-rows/{rowId}', [MigrationJobController::class, 'patchStagingRow'])->whereUuid('id')->whereUuid('rowId')->name('data-migration.jobs.staging-rows.patch');
        Route::post('jobs/{id}/commit', [MigrationJobController::class, 'commit'])->whereUuid('id')->name('data-migration.jobs.commit');
        Route::post('jobs/{id}/rollback', [MigrationJobController::class, 'rollback'])->whereUuid('id')->name('data-migration.jobs.rollback');
    });
