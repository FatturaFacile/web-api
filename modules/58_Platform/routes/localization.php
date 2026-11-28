<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Localization\Domain\Enums\TranslatableResource;
use Modules\Platform\Localization\Http\Controllers\DictionaryController;
use Modules\Platform\Localization\Http\Controllers\DocumentLocaleController;
use Modules\Platform\Localization\Http\Controllers\LocalizationSettingsController;
use Modules\Platform\Localization\Http\Controllers\ProfileLocaleController;
use Modules\Platform\Localization\Http\Controllers\ResourceTranslationController;
use Modules\Platform\Localization\Http\Controllers\TranslationAdminController;
use Modules\Platform\Localization\Http\Middleware\ResolveRequestLocale;

/*
| Localization and i18n engine (Mod 002, issue #331).
|
| The dictionary is public (the sign-in page needs it) and cached by version hash (ETag / If-None-Match). The profile locale
| belongs to the signed-in user. Everything else is tenant scoped: members read, owners and administrators write.
*/
Route::get('api/v1/i18n/dictionary', [DictionaryController::class, 'show'])
    ->middleware(['api', 'throttle:240,1'])
    ->name('api.v1.i18n.dictionary');

Route::put('api/v1/profile/locale', [ProfileLocaleController::class, 'update'])
    ->middleware(['api', 'auth:sanctum', 'tenant', ResolveRequestLocale::class])
    ->name('api.v1.profile.locale');

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', 'tenant', ResolveRequestLocale::class])
    ->name('api.v1.localization.')
    ->group(function (): void {
        $segment = '[0-9A-Za-z_\-]+';
        $resources = implode('|', TranslatableResource::slugs());

        Route::get('settings/localization', [LocalizationSettingsController::class, 'show'])->name('settings.show');
        Route::put('settings/localization', [LocalizationSettingsController::class, 'update'])->name('settings.update');

        Route::get('settings/translations', [TranslationAdminController::class, 'index'])->name('translations.index');
        Route::get('settings/translations/export', [TranslationAdminController::class, 'export'])->name('translations.export');
        Route::post('settings/translations/import', [TranslationAdminController::class, 'import'])->middleware('throttle:20,1')->name('translations.import');
        Route::put('settings/translations/{group}/{key}', [TranslationAdminController::class, 'update'])
            ->where(['group' => $segment, 'key' => '[0-9A-Za-z_.\-]+'])
            ->name('translations.update');

        Route::get('documents/{type}/{id}/locale', [DocumentLocaleController::class, 'show'])
            ->where(['type' => implode('|', array_keys(DocumentLocaleController::TYPES)), 'id' => $segment])
            ->name('documents.locale.show');
        Route::put('documents/{type}/{id}/locale', [DocumentLocaleController::class, 'update'])
            ->where(['type' => implode('|', array_keys(DocumentLocaleController::TYPES)), 'id' => $segment])
            ->name('documents.locale.update');
        Route::get('sales/invoices/{id}/italian-translation-print', [DocumentLocaleController::class, 'italianTranslation'])
            ->where('id', $segment)
            ->name('invoices.italian-translation-print');

        Route::get('{resource}/{id}/translations', [ResourceTranslationController::class, 'show'])
            ->where(['resource' => $resources, 'id' => $segment])
            ->name('resources.translations.show');
        Route::put('{resource}/{id}/translations', [ResourceTranslationController::class, 'update'])
            ->where(['resource' => $resources, 'id' => $segment])
            ->name('resources.translations.update');
    });
