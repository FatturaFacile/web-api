<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pricing\Http\Controllers\MarginApprovalController;
use Modules\Pricing\Http\Controllers\MarginPolicyController;
use Modules\Pricing\Http\Controllers\PriceHistoryController;
use Modules\Pricing\Http\Controllers\PriceListItemController;
use Modules\Pricing\Http\Controllers\PricingResolveController;
use Modules\Pricing\Http\Controllers\PromotionController;
use Modules\Pricing\Http\Controllers\SpecialPriceController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Advanced pricing: price list items with cascades, customer special prices, promotions, margin policies, price history,
| margin override approvals and the resolution endpoint. module.access:pricing lets finance and sales members read (GET)
| and only the roles with write access of config/permissions.php change anything.
*/
Route::prefix('api/v1/pricing')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:pricing'])
    ->name('api.v1.pricing.')
    ->where(['id' => $uuid, 'listId' => $uuid, 'documentId' => $uuid])
    ->group(function (): void {
        Route::post('/resolve', [PricingResolveController::class, 'resolve'])->name('resolve');

        Route::get('/price-lists/{listId}/items', [PriceListItemController::class, 'index'])->name('items.index');
        Route::post('/price-lists/{listId}/items', [PriceListItemController::class, 'store'])->name('items.store');
        Route::post('/price-lists/{listId}/items/bulk', [PriceListItemController::class, 'bulk'])->name('items.bulk');
        Route::put('/price-lists/{listId}/items/{id}', [PriceListItemController::class, 'update'])->name('items.update');
        Route::delete('/price-lists/{listId}/items/{id}', [PriceListItemController::class, 'destroy'])->name('items.destroy');

        Route::get('/price-history', [PriceHistoryController::class, 'index'])->name('history.index');

        Route::apiResource('special-prices', SpecialPriceController::class)->parameters(['special-prices' => 'id']);
        Route::apiResource('promotions', PromotionController::class)->parameters(['promotions' => 'id']);
        Route::apiResource('margin-policies', MarginPolicyController::class)->parameters(['margin-policies' => 'id']);

        Route::get('/margin-overrides', [MarginApprovalController::class, 'index'])->name('margin-overrides.index');
        Route::post('/margin-overrides/approve', [MarginApprovalController::class, 'approve'])->name('margin-overrides.approve');
        Route::get('/margin-overrides/check/{documentType}/{documentId}', [MarginApprovalController::class, 'check'])->name('margin-overrides.check');
    });
