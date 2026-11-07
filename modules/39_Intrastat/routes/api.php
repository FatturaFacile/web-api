<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Intrastat\Http\Controllers\CodeListController;
use Modules\Intrastat\Http\Controllers\DeclarationController;
use Modules\Intrastat\Http\Controllers\DeclarationLineController;
use Modules\Intrastat\Http\Controllers\ExportController;
use Modules\Intrastat\Http\Controllers\PeriodicityController;
use Modules\Intrastat\Http\Controllers\ProductProfileController;

$uuid = '[0-9a-fA-F-]{36}';

Route::prefix('api/v1/intrastat')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:intrastat'])
    ->name('api.v1.intrastat.')
    ->where(['id' => $uuid, 'lineId' => $uuid, 'productId' => $uuid])
    ->group(function (): void {
        Route::get('/declarations', [DeclarationController::class, 'index'])->name('declarations.index');
        Route::post('/declarations', [DeclarationController::class, 'store'])->name('declarations.store');
        Route::post('/declarations/generate', [DeclarationController::class, 'generate'])->name('declarations.generate');
        Route::get('/declarations/{id}', [DeclarationController::class, 'show'])->name('declarations.show');
        Route::put('/declarations/{id}', [DeclarationController::class, 'update'])->name('declarations.update');
        Route::delete('/declarations/{id}', [DeclarationController::class, 'destroy'])->name('declarations.destroy');
        Route::post('/declarations/{id}/validate', [DeclarationController::class, 'check'])->name('declarations.validate');
        Route::post('/declarations/{id}/submit', [DeclarationController::class, 'submit'])->name('declarations.submit');

        Route::post('/declarations/{id}/lines', [DeclarationLineController::class, 'store'])->name('lines.store');
        Route::put('/declarations/{id}/lines/{lineId}', [DeclarationLineController::class, 'update'])->name('lines.update');
        Route::delete('/declarations/{id}/lines/{lineId}', [DeclarationLineController::class, 'destroy'])->name('lines.destroy');

        Route::post('/exports', [ExportController::class, 'store'])->name('exports.store');
        Route::get('/periodicity', [PeriodicityController::class, 'show'])->name('periodicity');
        Route::get('/codes', [CodeListController::class, 'index'])->name('codes');

        Route::get('/product-profiles', [ProductProfileController::class, 'index'])->name('product-profiles.index');
        Route::put('/product-profiles/{productId}', [ProductProfileController::class, 'upsert'])->name('product-profiles.upsert');
        Route::delete('/product-profiles/{productId}', [ProductProfileController::class, 'destroy'])->name('product-profiles.destroy');
    });
