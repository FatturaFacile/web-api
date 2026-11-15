<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Events\Http\Controllers\EventController;
use Modules\Events\Http\Controllers\ProgrammeController;
use Modules\Events\Http\Controllers\RegistrationController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Event management. module.access:events lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy keeps the records inside the company.
*/
Route::prefix('api/v1/events')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:events'])
    ->name('api.v1.events.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/sessions', [ProgrammeController::class, 'sessions'])->name('sessions.index');
        Route::post('/sessions', [ProgrammeController::class, 'storeSession'])->name('sessions.store');
        Route::put('/sessions/{id}', [ProgrammeController::class, 'updateSession'])->name('sessions.update');
        Route::delete('/sessions/{id}', [ProgrammeController::class, 'destroySession'])->name('sessions.destroy');

        Route::get('/ticket-types', [ProgrammeController::class, 'ticketTypes'])->name('ticket-types.index');
        Route::post('/ticket-types', [ProgrammeController::class, 'storeTicketType'])->name('ticket-types.store');
        Route::put('/ticket-types/{id}', [ProgrammeController::class, 'updateTicketType'])->name('ticket-types.update');
        Route::delete('/ticket-types/{id}', [ProgrammeController::class, 'destroyTicketType'])->name('ticket-types.destroy');

        Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::post('/registrations', [RegistrationController::class, 'store'])->name('registrations.store');
        Route::get('/registrations/{id}', [RegistrationController::class, 'show'])->name('registrations.show');
        Route::put('/registrations/{id}', [RegistrationController::class, 'update'])->name('registrations.update');
        Route::post('/registrations/{id}/cancel', [RegistrationController::class, 'cancel'])->name('registrations.cancel');
        Route::post('/registrations/{id}/pay', [RegistrationController::class, 'pay'])->name('registrations.pay');
        Route::get('/registrations/{id}/qr', [RegistrationController::class, 'qr'])->name('registrations.qr');
        Route::post('/check-in', [RegistrationController::class, 'checkIn'])->name('check-in');

        Route::get('/', [EventController::class, 'index'])->name('index');
        Route::post('/', [EventController::class, 'store'])->name('store');
        Route::get('/{id}', [EventController::class, 'show'])->name('show');
        Route::put('/{id}', [EventController::class, 'update'])->name('update');
        Route::delete('/{id}', [EventController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/publish', [EventController::class, 'publish'])->name('publish');
        Route::post('/{id}/close', [EventController::class, 'close'])->name('close');
        Route::post('/{id}/cancel', [EventController::class, 'cancel'])->name('cancel');
        Route::get('/{id}/report', [EventController::class, 'report'])->name('report');
    });
