<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Visitors\Http\Controllers\KioskApiController;
use Modules\Visitors\Http\Controllers\KioskController;
use Modules\Visitors\Http\Controllers\VisitController;
use Modules\Visitors\Http\Controllers\VisitorController;
use Modules\Visitors\Http\Middleware\AuthenticateVisitorKiosk;

$uuid = '[0-9a-fA-F-]{36}';

Route::prefix('api/v1/visitors')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:visitors'])
    ->name('api.v1.visitors.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/visitors', [VisitorController::class, 'index'])->name('visitors.index');
        Route::post('/visitors', [VisitorController::class, 'store'])->name('visitors.store');
        Route::get('/visitors/{id}', [VisitorController::class, 'show'])->name('visitors.show');
        Route::put('/visitors/{id}', [VisitorController::class, 'update'])->name('visitors.update');
        Route::delete('/visitors/{id}', [VisitorController::class, 'destroy'])->name('visitors.destroy');

        Route::get('/visits', [VisitController::class, 'index'])->name('visits.index');
        Route::post('/visits', [VisitController::class, 'store'])->name('visits.store');
        Route::get('/visits/{id}', [VisitController::class, 'show'])->name('visits.show');
        Route::post('/visits/{id}/check-in', [VisitController::class, 'checkIn'])->name('visits.check-in');
        Route::post('/visits/{id}/check-out', [VisitController::class, 'checkOut'])->name('visits.check-out');
        Route::post('/visits/{id}/cancel', [VisitController::class, 'cancel'])->name('visits.cancel');
        Route::get('/on-site', [VisitController::class, 'onSite'])->name('on-site');

        Route::get('/kiosks', [KioskController::class, 'index'])->name('kiosks.index');
        Route::post('/kiosks', [KioskController::class, 'store'])->name('kiosks.store');
        Route::post('/kiosks/{id}/rotate-secret', [KioskController::class, 'rotate'])->name('kiosks.rotate');
        Route::delete('/kiosks/{id}', [KioskController::class, 'destroy'])->name('kiosks.destroy');
    });

// Reception tablet: the kiosk key and secret select the tenant.
Route::prefix('api/v1/visitor-kiosk')
    ->middleware(['api', 'locale', 'throttle:60,1', AuthenticateVisitorKiosk::class])
    ->name('api.v1.visitor-kiosk.')
    ->group(function (): void {
        Route::get('/expected', [KioskApiController::class, 'expected'])->name('expected');
        Route::post('/check-in', [KioskApiController::class, 'checkIn'])->name('check-in');
        Route::post('/check-out', [KioskApiController::class, 'checkOut'])->name('check-out');
    });
