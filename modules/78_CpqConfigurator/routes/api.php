<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CpqConfigurator\Http\Controllers\BomMappingController;
use Modules\CpqConfigurator\Http\Controllers\CharacteristicController;
use Modules\CpqConfigurator\Http\Controllers\ConfigurationController;
use Modules\CpqConfigurator\Http\Controllers\ModelController;
use Modules\CpqConfigurator\Http\Controllers\PricingFormulaController;
use Modules\CpqConfigurator\Http\Controllers\RoutingMappingController;
use Modules\CpqConfigurator\Http\Controllers\RuleController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Mod 230 — technical-commercial CPQ configurator. module.access:cpq_configurator lets every member read (GET) and only the
| roles with write access of config/permissions.php change anything; the policy reserves the definition of models,
| characteristics, rules, mappings and formulas to managers. The evaluation, the proposal preview and the checks save
| nothing, so they only ask for read access.
*/
Route::prefix('api/v1/cpq-configurator')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale'])
    ->name('api.v1.cpq-configurator.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::middleware('module.access:cpq_configurator,read')->group(function (): void {
            Route::post('/models/{id}/evaluate', [ConfigurationController::class, 'evaluate'])->name('models.evaluate');
            Route::post('/models/{id}/proposal', [ConfigurationController::class, 'proposal'])->name('models.proposal');
            Route::post('/models/{id}/expression-check', [ModelController::class, 'checkExpression'])->name('models.expression-check');
        });

        Route::middleware('module.access:cpq_configurator')->group(function (): void {
            Route::get('/models', [ModelController::class, 'index'])->name('models.index');
            Route::post('/models', [ModelController::class, 'store'])->name('models.store');
            Route::get('/models/{id}', [ModelController::class, 'show'])->name('models.show');
            Route::put('/models/{id}', [ModelController::class, 'update'])->name('models.update');
            Route::delete('/models/{id}', [ModelController::class, 'destroy'])->name('models.destroy');
            Route::post('/models/{id}/publish', [ModelController::class, 'publish'])->name('models.publish');
            Route::post('/models/{id}/archive', [ModelController::class, 'archive'])->name('models.archive');
            Route::get('/models/{id}/diagnostics', [ModelController::class, 'diagnostics'])->name('models.diagnostics');
            Route::post('/models/{id}/confirm', [ConfigurationController::class, 'confirm'])->name('models.confirm');

            Route::post('/models/{id}/characteristics', [CharacteristicController::class, 'store'])->name('characteristics.store');
            Route::put('/characteristics/{id}', [CharacteristicController::class, 'update'])->name('characteristics.update');
            Route::delete('/characteristics/{id}', [CharacteristicController::class, 'destroy'])->name('characteristics.destroy');
            Route::post('/characteristics/{id}/options', [CharacteristicController::class, 'storeOption'])->name('options.store');
            Route::put('/options/{id}', [CharacteristicController::class, 'updateOption'])->name('options.update');
            Route::delete('/options/{id}', [CharacteristicController::class, 'destroyOption'])->name('options.destroy');

            Route::get('/models/{id}/rules', [RuleController::class, 'index'])->name('rules.index');
            Route::post('/models/{id}/rules', [RuleController::class, 'store'])->name('rules.store');
            Route::put('/rules/{id}', [RuleController::class, 'update'])->name('rules.update');
            Route::delete('/rules/{id}', [RuleController::class, 'destroy'])->name('rules.destroy');

            Route::get('/models/{id}/superbom-mappings', [BomMappingController::class, 'index'])->name('superbom-mappings.index');
            Route::post('/models/{id}/superbom-mappings', [BomMappingController::class, 'store'])->name('superbom-mappings.store');
            Route::put('/superbom-mappings/{id}', [BomMappingController::class, 'update'])->name('superbom-mappings.update');
            Route::delete('/superbom-mappings/{id}', [BomMappingController::class, 'destroy'])->name('superbom-mappings.destroy');

            Route::get('/models/{id}/routing-mappings', [RoutingMappingController::class, 'index'])->name('routing-mappings.index');
            Route::post('/models/{id}/routing-mappings', [RoutingMappingController::class, 'store'])->name('routing-mappings.store');
            Route::put('/routing-mappings/{id}', [RoutingMappingController::class, 'update'])->name('routing-mappings.update');
            Route::delete('/routing-mappings/{id}', [RoutingMappingController::class, 'destroy'])->name('routing-mappings.destroy');

            Route::get('/models/{id}/pricing-formulas', [PricingFormulaController::class, 'index'])->name('pricing-formulas.index');
            Route::post('/models/{id}/pricing-formulas', [PricingFormulaController::class, 'store'])->name('pricing-formulas.store');
            Route::put('/pricing-formulas/{id}', [PricingFormulaController::class, 'update'])->name('pricing-formulas.update');
            Route::delete('/pricing-formulas/{id}', [PricingFormulaController::class, 'destroy'])->name('pricing-formulas.destroy');

            Route::get('/instances', [ConfigurationController::class, 'index'])->name('instances.index');
            Route::get('/instances/{id}', [ConfigurationController::class, 'show'])->name('instances.show');
            Route::get('/instances/{id}/proposal', [ConfigurationController::class, 'instanceProposal'])->name('instances.proposal');
            Route::get('/instances/{id}/proposal/pdf', [ConfigurationController::class, 'instanceProposalPdf'])->name('instances.proposal-pdf');
        });
    });
