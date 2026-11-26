<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ProductCatalog\Http\Controllers\AttributeController;
use Modules\ProductCatalog\Http\Controllers\KitController;
use Modules\ProductCatalog\Http\Controllers\LookupController;
use Modules\ProductCatalog\Http\Controllers\MatrixController;
use Modules\ProductCatalog\Http\Controllers\PackagingController;
use Modules\ProductCatalog\Http\Controllers\ProductController;
use Modules\ProductCatalog\Http\Controllers\TransferController;

/*
|--------------------------------------------------------------------------
| Product catalog (Mod 052 / Issue #248)
|--------------------------------------------------------------------------
| Polymorphic products, multi-attribute variant matrices, phantom and physical kits, multi-level units of measure with exact
| conversions, CH / IT customs and VAT classification, and bulk import / export (csv, xlsx). Authenticated, per company.
*/
Route::prefix('api/v1/catalog')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:product_catalog'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('catalog.lookups');
        Route::get('customs-codes', [LookupController::class, 'customsCodes'])->name('catalog.customs-codes');

        Route::get('attributes', [AttributeController::class, 'index'])->name('catalog.attributes.index');
        Route::post('attributes', [AttributeController::class, 'store'])->name('catalog.attributes.store');
        Route::put('attributes/{id}', [AttributeController::class, 'update'])->whereUuid('id')->name('catalog.attributes.update');
        Route::delete('attributes/{id}', [AttributeController::class, 'destroy'])->whereUuid('id')->name('catalog.attributes.destroy');

        Route::get('products', [ProductController::class, 'index'])->name('catalog.products.index');
        Route::post('products', [ProductController::class, 'store'])->name('catalog.products.store');
        Route::get('products/{id}', [ProductController::class, 'show'])->whereUuid('id')->name('catalog.products.show');
        Route::put('products/{id}', [ProductController::class, 'update'])->whereUuid('id')->name('catalog.products.update');
        Route::delete('products/{id}', [ProductController::class, 'destroy'])->whereUuid('id')->name('catalog.products.destroy');

        Route::post('products/{id}/matrix/preview', [MatrixController::class, 'preview'])->whereUuid('id')->name('catalog.matrix.preview');
        Route::post('products/{id}/matrix/generate', [MatrixController::class, 'generate'])->whereUuid('id')->name('catalog.matrix.generate');
        Route::post('products/{id}/matrix/reprice', [MatrixController::class, 'reprice'])->whereUuid('id')->name('catalog.matrix.reprice');

        Route::put('products/{id}/kit-components', [KitController::class, 'update'])->whereUuid('id')->name('catalog.kits.components');
        Route::get('products/{id}/explosion', [KitController::class, 'explosion'])->whereUuid('id')->name('catalog.kits.explosion');
        Route::post('kits/explode', [KitController::class, 'explodeLines'])->name('catalog.kits.explode');

        Route::put('products/{id}/packagings', [PackagingController::class, 'update'])->whereUuid('id')->name('catalog.packagings.update');
        Route::post('products/{id}/convert', [PackagingController::class, 'convert'])->whereUuid('id')->name('catalog.packagings.convert');

        Route::get('export', [TransferController::class, 'export'])->name('catalog.export');
        Route::get('import/template', [TransferController::class, 'template'])->name('catalog.import.template');
        Route::get('import/schema', [TransferController::class, 'schema'])->name('catalog.import.schema');
        Route::post('import', [TransferController::class, 'import'])->name('catalog.import');
    });
