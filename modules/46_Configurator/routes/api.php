<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Configurator\Http\Controllers\ConfigurationController;
use Modules\Configurator\Http\Controllers\GroupController;
use Modules\Configurator\Http\Controllers\ModelController;
use Modules\Configurator\Http\Controllers\OptionController;
use Modules\Configurator\Http\Controllers\RuleController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Configure-price-quote. module.access:configurator lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy reserves the definition of models, options and rules to managers
| and the approval of a discount below the minimum margin to approvers.
*/
Route::prefix('api/v1/configurator')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:configurator'])
    ->name('api.v1.configurator.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/models', [ModelController::class, 'index'])->name('models.index');
        Route::post('/models', [ModelController::class, 'store'])->name('models.store');
        Route::get('/models/{id}', [ModelController::class, 'show'])->name('models.show');
        Route::put('/models/{id}', [ModelController::class, 'update'])->name('models.update');
        Route::delete('/models/{id}', [ModelController::class, 'destroy'])->name('models.destroy');
        Route::post('/models/{id}/publish', [ModelController::class, 'publish'])->name('models.publish');
        Route::post('/models/{id}/archive', [ModelController::class, 'archive'])->name('models.archive');
        Route::get('/models/{id}/rule-check', [ModelController::class, 'ruleCheck'])->name('models.rule-check');
        Route::post('/models/{id}/evaluate', [ModelController::class, 'evaluate'])->name('models.evaluate');

        Route::post('/models/{id}/groups', [GroupController::class, 'store'])->name('groups.store');
        Route::put('/groups/{id}', [GroupController::class, 'update'])->name('groups.update');
        Route::delete('/groups/{id}', [GroupController::class, 'destroy'])->name('groups.destroy');

        Route::post('/groups/{id}/options', [OptionController::class, 'store'])->name('options.store');
        Route::put('/options/{id}', [OptionController::class, 'update'])->name('options.update');
        Route::delete('/options/{id}', [OptionController::class, 'destroy'])->name('options.destroy');

        Route::get('/models/{id}/rules', [RuleController::class, 'index'])->name('rules.index');
        Route::post('/models/{id}/rules', [RuleController::class, 'store'])->name('rules.store');
        Route::put('/rules/{id}', [RuleController::class, 'update'])->name('rules.update');
        Route::delete('/rules/{id}', [RuleController::class, 'destroy'])->name('rules.destroy');

        Route::get('/configurations', [ConfigurationController::class, 'index'])->name('configurations.index');
        Route::post('/configurations', [ConfigurationController::class, 'store'])->name('configurations.store');
        Route::get('/configurations/{id}', [ConfigurationController::class, 'show'])->name('configurations.show');
        Route::put('/configurations/{id}', [ConfigurationController::class, 'update'])->name('configurations.update');
        Route::delete('/configurations/{id}', [ConfigurationController::class, 'destroy'])->name('configurations.destroy');
        Route::post('/configurations/{id}/validate', [ConfigurationController::class, 'validateConfiguration'])->name('configurations.validate');
        Route::post('/configurations/{id}/request-approval', [ConfigurationController::class, 'requestApproval'])->name('configurations.request-approval');
        Route::post('/configurations/{id}/approve', [ConfigurationController::class, 'approve'])->name('configurations.approve');
        Route::post('/configurations/{id}/reject', [ConfigurationController::class, 'reject'])->name('configurations.reject');
        Route::post('/configurations/{id}/convert-to-quote', [ConfigurationController::class, 'convertToQuote'])->name('configurations.convert-to-quote');
    });
