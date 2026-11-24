<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\GelatoCatalogController;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\GelatoLotController;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\GelatoOrderController;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\GelatoRecipeController;
use Modules\ArtisanalGelato\Presentation\Http\Controllers\GelatoShowcaseController;
use Modules\ArtisanalGelato\Presentation\Http\Middleware\ResolveGelatoPublicTenant;

// artisanal_gelato: laboratory staff and the shop floor. Reads are open to every member, writes to the laboratory roles.
Route::prefix('api/v1/gelato')->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:artisanal_gelato'])->group(function (): void {
    Route::get('ingredients', [GelatoCatalogController::class, 'index'])->name('gelato.ingredients.index');
    Route::post('ingredients', [GelatoCatalogController::class, 'store'])->name('gelato.ingredients.store');
    Route::put('ingredients/{id}', [GelatoCatalogController::class, 'update'])->whereUuid('id')->name('gelato.ingredients.update');
    Route::get('reference', [GelatoCatalogController::class, 'reference'])->name('gelato.reference');

    Route::get('recipes', [GelatoRecipeController::class, 'index'])->name('gelato.recipes.index');
    Route::post('recipes', [GelatoRecipeController::class, 'store'])->name('gelato.recipes.store');
    Route::post('recipes/balance', [GelatoRecipeController::class, 'balance'])->name('gelato.recipes.balance');
    Route::get('recipes/{id}', [GelatoRecipeController::class, 'show'])->whereUuid('id')->name('gelato.recipes.show');
    Route::put('recipes/{id}', [GelatoRecipeController::class, 'update'])->whereUuid('id')->name('gelato.recipes.update');
    Route::post('recipes/{id}/clone', [GelatoRecipeController::class, 'clone'])->whereUuid('id')->name('gelato.recipes.clone');
    Route::post('recipes/{id}/scale', [GelatoRecipeController::class, 'scale'])->whereUuid('id')->name('gelato.recipes.scale');
    Route::post('ganache/calculate', [GelatoRecipeController::class, 'ganache'])->name('gelato.ganache.calculate');
    Route::put('recipes/{id}/ganache-spec', [GelatoRecipeController::class, 'saveGanacheSpec'])->whereUuid('id')->name('gelato.ganache.spec');

    Route::get('lots', [GelatoLotController::class, 'index'])->name('gelato.lots.index');
    Route::post('lots', [GelatoLotController::class, 'store'])->name('gelato.lots.store');
    Route::post('lots/{id}/haccp-readings', [GelatoLotController::class, 'haccp'])->whereUuid('id')->name('gelato.lots.haccp');
    Route::post('lots/{id}/complete', [GelatoLotController::class, 'complete'])->whereUuid('id')->name('gelato.lots.complete');
    Route::get('lots/{id}/genealogy', [GelatoLotController::class, 'genealogy'])->whereUuid('id')->name('gelato.lots.genealogy');
    Route::post('scale-readings', [GelatoLotController::class, 'scale'])->name('gelato.scale.store');

    Route::get('orders', [GelatoOrderController::class, 'index'])->name('gelato.orders.index');
    Route::post('orders', [GelatoOrderController::class, 'store'])->name('gelato.orders.store');
    Route::patch('orders/{id}/status', [GelatoOrderController::class, 'status'])->whereUuid('id')->name('gelato.orders.status');

    Route::get('showcase/{locationKey}', [GelatoShowcaseController::class, 'current'])->name('gelato.showcase.current');
    Route::post('showcase/{locationKey}/publish', [GelatoShowcaseController::class, 'publish'])->name('gelato.showcase.publish');
    Route::get('showcase/{locationKey}/book', [GelatoShowcaseController::class, 'book'])->name('gelato.showcase.book');
});

// The QR code of the showcase: the signature is the credential (no token, no tenant header). Outside the gelato prefix on purpose,
// because every route under it is behind module.access.
Route::prefix('api/v1/public/gelato/{tenantId}')->middleware(['api', 'throttle:60,1', 'signed', ResolveGelatoPublicTenant::class])->group(function (): void {
    Route::get('showcase/{locationKey}/book', [GelatoShowcaseController::class, 'publicBook'])->whereUuid('tenantId')->name('gelato.public.book');
});
