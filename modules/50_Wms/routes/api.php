<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Wms\Http\Controllers\CrossDockController;
use Modules\Wms\Http\Controllers\DashboardController;
use Modules\Wms\Http\Controllers\DockAppointmentActionController;
use Modules\Wms\Http\Controllers\DockAppointmentController;
use Modules\Wms\Http\Controllers\DockDoorController;
use Modules\Wms\Http\Controllers\SlottingActionController;
use Modules\Wms\Http\Controllers\SlottingRecommendationController;
use Modules\Wms\Http\Controllers\SlottingRuleController;
use Modules\Wms\Http\Controllers\TaskActionController;
use Modules\Wms\Http\Controllers\TaskController;
use Modules\Wms\Http\Controllers\WaveActionController;
use Modules\Wms\Http\Controllers\WaveController;
use Modules\Wms\Http\Controllers\WaveRuleController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Advanced warehouse management. module.access:wms lets every member read (GET) and only the roles with write access of config/permissions.php change anything; the policy keeps the records inside the company.
*/
Route::prefix('api/v1/wms')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:wms'])
    ->name('api.v1.wms.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');

        Route::get('/dock-doors', [DockDoorController::class, 'index'])->name('dock-doors.index');
        Route::post('/dock-doors', [DockDoorController::class, 'store'])->name('dock-doors.store');
        Route::get('/dock-doors/{id}', [DockDoorController::class, 'show'])->name('dock-doors.show');
        Route::put('/dock-doors/{id}', [DockDoorController::class, 'update'])->name('dock-doors.update');
        Route::delete('/dock-doors/{id}', [DockDoorController::class, 'destroy'])->name('dock-doors.destroy');

        Route::get('/wave-rules', [WaveRuleController::class, 'index'])->name('wave-rules.index');
        Route::post('/wave-rules', [WaveRuleController::class, 'store'])->name('wave-rules.store');
        Route::get('/wave-rules/{id}', [WaveRuleController::class, 'show'])->name('wave-rules.show');
        Route::put('/wave-rules/{id}', [WaveRuleController::class, 'update'])->name('wave-rules.update');
        Route::delete('/wave-rules/{id}', [WaveRuleController::class, 'destroy'])->name('wave-rules.destroy');

        Route::get('/waves', [WaveController::class, 'index'])->name('waves.index');
        Route::get('/waves/{id}', [WaveController::class, 'show'])->name('waves.show');
        Route::post('/waves/plan', [WaveActionController::class, 'plan'])->name('waves.plan');
        Route::post('/waves/{id}/release', [WaveActionController::class, 'release'])->name('waves.release');
        Route::post('/waves/{id}/complete', [WaveActionController::class, 'complete'])->name('waves.complete');
        Route::post('/waves/{id}/cancel', [WaveActionController::class, 'cancel'])->name('waves.cancel');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{id}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('/tasks/{id}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{id}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        Route::post('/tasks/claim-next', [TaskActionController::class, 'claimNext'])->name('tasks.claim-next');
        Route::post('/tasks/{id}/assign', [TaskActionController::class, 'assign'])->name('tasks.assign');
        Route::post('/tasks/{id}/start', [TaskActionController::class, 'start'])->name('tasks.start');
        Route::post('/tasks/{id}/confirm', [TaskActionController::class, 'confirm'])->name('tasks.confirm');
        Route::post('/tasks/{id}/short', [TaskActionController::class, 'short'])->name('tasks.short');
        Route::post('/tasks/{id}/skip', [TaskActionController::class, 'skip'])->name('tasks.skip');
        Route::post('/tasks/{id}/cancel', [TaskActionController::class, 'cancel'])->name('tasks.cancel');

        Route::get('/slotting-rules', [SlottingRuleController::class, 'index'])->name('slotting-rules.index');
        Route::post('/slotting-rules', [SlottingRuleController::class, 'store'])->name('slotting-rules.store');
        Route::get('/slotting-rules/{id}', [SlottingRuleController::class, 'show'])->name('slotting-rules.show');
        Route::put('/slotting-rules/{id}', [SlottingRuleController::class, 'update'])->name('slotting-rules.update');
        Route::delete('/slotting-rules/{id}', [SlottingRuleController::class, 'destroy'])->name('slotting-rules.destroy');

        Route::get('/slotting-recommendations', [SlottingRecommendationController::class, 'index'])->name('slotting-recommendations.index');
        Route::get('/slotting-recommendations/{id}', [SlottingRecommendationController::class, 'show'])->name('slotting-recommendations.show');
        Route::post('/slotting-recommendations/generate', [SlottingActionController::class, 'generate'])->name('slotting-recommendations.generate');
        Route::post('/slotting-recommendations/{id}/accept', [SlottingActionController::class, 'accept'])->name('slotting-recommendations.accept');
        Route::post('/slotting-recommendations/{id}/reject', [SlottingActionController::class, 'reject'])->name('slotting-recommendations.reject');

        Route::get('/dock-appointments', [DockAppointmentController::class, 'index'])->name('dock-appointments.index');
        Route::post('/dock-appointments', [DockAppointmentController::class, 'store'])->name('dock-appointments.store');
        Route::get('/dock-appointments/{id}', [DockAppointmentController::class, 'show'])->name('dock-appointments.show');
        Route::put('/dock-appointments/{id}', [DockAppointmentController::class, 'update'])->name('dock-appointments.update');
        Route::delete('/dock-appointments/{id}', [DockAppointmentController::class, 'destroy'])->name('dock-appointments.destroy');
        Route::post('/dock-appointments/{id}/check-in', [DockAppointmentActionController::class, 'checkIn'])->name('dock-appointments.check-in');
        Route::post('/dock-appointments/{id}/complete', [DockAppointmentActionController::class, 'complete'])->name('dock-appointments.complete');
        Route::post('/dock-appointments/{id}/cancel', [DockAppointmentActionController::class, 'cancel'])->name('dock-appointments.cancel');
        Route::post('/dock-appointments/{id}/no-show', [DockAppointmentActionController::class, 'noShow'])->name('dock-appointments.no-show');

        Route::get('/cross-dock-orders', [CrossDockController::class, 'index'])->name('cross-dock-orders.index');
        Route::post('/cross-dock-orders', [CrossDockController::class, 'store'])->name('cross-dock-orders.store');
        Route::get('/cross-dock-orders/{id}', [CrossDockController::class, 'show'])->name('cross-dock-orders.show');
        Route::put('/cross-dock-orders/{id}', [CrossDockController::class, 'update'])->name('cross-dock-orders.update');
        Route::delete('/cross-dock-orders/{id}', [CrossDockController::class, 'destroy'])->name('cross-dock-orders.destroy');
        Route::post('/cross-dock-orders/{id}/receive', [CrossDockController::class, 'receive'])->name('cross-dock-orders.receive');
        Route::post('/cross-dock-orders/{id}/allocate', [CrossDockController::class, 'allocate'])->name('cross-dock-orders.allocate');
        Route::post('/cross-dock-orders/{id}/ship', [CrossDockController::class, 'ship'])->name('cross-dock-orders.ship');
        Route::post('/cross-dock-orders/{id}/cancel', [CrossDockController::class, 'cancel'])->name('cross-dock-orders.cancel');
    });
