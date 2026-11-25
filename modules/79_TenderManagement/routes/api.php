<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TenderManagement\Http\Controllers\AlertController;
use Modules\TenderManagement\Http\Controllers\BenchmarkController;
use Modules\TenderManagement\Http\Controllers\EnvelopeController;
use Modules\TenderManagement\Http\Controllers\LookupController;
use Modules\TenderManagement\Http\Controllers\NoticeController;
use Modules\TenderManagement\Http\Controllers\ParticipationController;
use Modules\TenderManagement\Http\Controllers\VaultController;

/*
|--------------------------------------------------------------------------
| Public and private tender management (Mod 169 / Issue #415)
|--------------------------------------------------------------------------
| simap.ch LAPub / IVöB and ANAC / MePA D.Lgs. 36/2023 tenders: notices, participation dossiers with the three envelopes,
| the Go/No-Go evaluation, the economic offer and bid bond simulator, the qualification vault, competitor benchmarks, the
| deadline alerts and the conversion of an award into a contract (Mod 108) and a project (Mod 065).
*/
Route::prefix('api/v1/tender-management')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:tender_management'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('tender-management.lookups');
        Route::get('dashboard', [LookupController::class, 'dashboard'])->name('tender-management.dashboard');

        Route::get('notices', [NoticeController::class, 'index'])->name('tender-management.notices.index');
        Route::post('notices', [NoticeController::class, 'store'])->name('tender-management.notices.store');
        Route::post('notices/ingest-simap', [NoticeController::class, 'ingestSimap'])->name('tender-management.notices.ingest-simap');
        Route::get('notices/{id}', [NoticeController::class, 'show'])->whereUuid('id')->name('tender-management.notices.show');
        Route::put('notices/{id}', [NoticeController::class, 'update'])->whereUuid('id')->name('tender-management.notices.update');
        Route::delete('notices/{id}', [NoticeController::class, 'destroy'])->whereUuid('id')->name('tender-management.notices.destroy');

        Route::get('participations', [ParticipationController::class, 'index'])->name('tender-management.participations.index');
        Route::post('participations', [ParticipationController::class, 'store'])->name('tender-management.participations.store');
        Route::get('participations/{id}', [ParticipationController::class, 'show'])->whereUuid('id')->name('tender-management.participations.show');
        Route::put('participations/{id}', [ParticipationController::class, 'update'])->whereUuid('id')->name('tender-management.participations.update');
        Route::delete('participations/{id}', [ParticipationController::class, 'destroy'])->whereUuid('id')->name('tender-management.participations.destroy');
        Route::post('participations/{id}/transition', [ParticipationController::class, 'transition'])->whereUuid('id')->name('tender-management.participations.transition');
        Route::post('participations/{id}/evaluate-eligibility', [ParticipationController::class, 'evaluate'])->whereUuid('id')->name('tender-management.participations.evaluate');
        Route::post('participations/{id}/economic-offer', [ParticipationController::class, 'economicOffer'])->whereUuid('id')->name('tender-management.participations.economic-offer');
        Route::get('participations/{id}/readiness', [ParticipationController::class, 'readiness'])->whereUuid('id')->name('tender-management.participations.readiness');
        Route::post('participations/{id}/submit', [ParticipationController::class, 'submit'])->whereUuid('id')->name('tender-management.participations.submit');
        Route::post('participations/{id}/convert', [ParticipationController::class, 'convert'])->whereUuid('id')->name('tender-management.participations.convert');
        Route::get('participations/{id}/edgue', [ParticipationController::class, 'edgue'])->whereUuid('id')->name('tender-management.participations.edgue');
        Route::get('participations/{id}/edgue/download', [ParticipationController::class, 'edgueDownload'])->whereUuid('id')->name('tender-management.participations.edgue-download');

        Route::put('participations/{id}/envelopes/{envelope}', [EnvelopeController::class, 'define'])->whereUuid('id')->name('tender-management.envelopes.define');
        Route::post('participations/{id}/envelopes/{envelope}/seed', [EnvelopeController::class, 'seed'])->whereUuid('id')->name('tender-management.envelopes.seed');
        Route::post('participations/{id}/envelopes/{envelope}/documents', [EnvelopeController::class, 'upload'])->whereUuid('id')->name('tender-management.envelopes.upload');
        Route::delete('participations/{id}/envelopes/{envelope}/documents/{docKey}', [EnvelopeController::class, 'remove'])->whereUuid('id')->name('tender-management.envelopes.remove');

        Route::get('vault/overview', [VaultController::class, 'overview'])->name('tender-management.vault.overview');
        Route::get('vault/documents', [VaultController::class, 'index'])->name('tender-management.vault.index');
        Route::post('vault/documents', [VaultController::class, 'store'])->name('tender-management.vault.store');
        Route::get('vault/documents/{id}', [VaultController::class, 'show'])->whereUuid('id')->name('tender-management.vault.show');
        Route::put('vault/documents/{id}', [VaultController::class, 'update'])->whereUuid('id')->name('tender-management.vault.update');
        Route::delete('vault/documents/{id}', [VaultController::class, 'destroy'])->whereUuid('id')->name('tender-management.vault.destroy');
        Route::post('vault/documents/{id}/renew', [VaultController::class, 'renew'])->whereUuid('id')->name('tender-management.vault.renew');
        Route::post('vault/documents/{id}/sync-fvoe', [VaultController::class, 'syncFvoe'])->whereUuid('id')->name('tender-management.vault.sync-fvoe');

        Route::get('benchmarks/statistics', [BenchmarkController::class, 'statistics'])->name('tender-management.benchmarks.statistics');
        Route::get('benchmarks', [BenchmarkController::class, 'index'])->name('tender-management.benchmarks.index');
        Route::post('benchmarks', [BenchmarkController::class, 'store'])->name('tender-management.benchmarks.store');
        Route::put('benchmarks/{id}', [BenchmarkController::class, 'update'])->whereUuid('id')->name('tender-management.benchmarks.update');
        Route::delete('benchmarks/{id}', [BenchmarkController::class, 'destroy'])->whereUuid('id')->name('tender-management.benchmarks.destroy');

        Route::get('alerts', [AlertController::class, 'index'])->name('tender-management.alerts.index');
        Route::post('alerts/{id}/acknowledge', [AlertController::class, 'acknowledge'])->whereUuid('id')->name('tender-management.alerts.acknowledge');
    });
