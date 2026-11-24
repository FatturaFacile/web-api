<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Academy\Http\Controllers\AcademyCertificateController;
use Modules\Academy\Http\Controllers\AcademyClassroomController;
use Modules\Academy\Http\Controllers\AcademyLearningController;

// Public: anybody holding a certificate code can verify it.
Route::prefix('api/v1/academy')
    ->middleware(['api', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('certificates/verify/{certificateCode}', [AcademyCertificateController::class, 'verify'])->name('academy.certificates.verify');
    });

Route::prefix('api/v1/academy')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:academy', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('contextual-help', [AcademyLearningController::class, 'contextualHelp'])->name('academy.contextual-help');
        Route::get('tracks', [AcademyLearningController::class, 'tracks'])->name('academy.tracks.index');
        Route::get('tracks/{trackId}', [AcademyLearningController::class, 'track'])->whereUuid('trackId')->name('academy.tracks.show');
        Route::get('tracks/{trackId}/certificate', [AcademyCertificateController::class, 'download'])->whereUuid('trackId')->name('academy.certificates.download');
        Route::get('progress', [AcademyLearningController::class, 'progress'])->name('academy.progress');
        Route::post('missions/{missionId}/start', [AcademyLearningController::class, 'startMission'])->whereUuid('missionId')->name('academy.missions.start');
        Route::post('missions/{missionId}/verify-step', [AcademyLearningController::class, 'verifyStep'])->whereUuid('missionId')->name('academy.missions.verify-step');
        Route::post('classroom/cohorts', [AcademyClassroomController::class, 'store'])->name('academy.classroom.cohorts.store');
        Route::post('classroom/join', [AcademyClassroomController::class, 'join'])->name('academy.classroom.join');
        Route::get('classroom/cohorts/{cohortId}/dashboard', [AcademyClassroomController::class, 'dashboard'])->whereUuid('cohortId')->name('academy.classroom.dashboard');
    });
