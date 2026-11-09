<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Hospitality\Http\Controllers\FloorController;
use Modules\Hospitality\Http\Controllers\OrderController;
use Modules\Hospitality\Http\Controllers\ReservationController;

Route::prefix('api/v1/hospitality')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:hospitality'])
    ->name('api.v1.hospitality.')
    ->where(['id' => '[0-9a-fA-F-]{36}'])
    ->group(function (): void {
        Route::get('/rooms', [FloorController::class, 'rooms'])->name('rooms.index');
        Route::post('/rooms', [FloorController::class, 'storeRoom'])->name('rooms.store');
        Route::get('/rooms/{id}/plan', [FloorController::class, 'plan'])->name('rooms.plan');
        Route::post('/rooms/{id}/tables', [FloorController::class, 'storeTable'])->name('rooms.tables.store');
        Route::put('/tables/{id}', [FloorController::class, 'updateTable'])->name('tables.update');

        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/availability', [ReservationController::class, 'availability'])->name('reservations.availability');
        Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
        Route::get('/reservations/{id}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::put('/reservations/{id}', [ReservationController::class, 'update'])->name('reservations.update');
        Route::post('/reservations/{id}/seat', [ReservationController::class, 'seat'])->name('reservations.seat');
        Route::post('/reservations/{id}/status', [ReservationController::class, 'status'])->name('reservations.status');

        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{id}/items', [OrderController::class, 'storeItems'])->name('orders.items.store');
        Route::post('/orders/{id}/fire', [OrderController::class, 'fire'])->name('orders.fire');
        Route::post('/orders/{id}/move', [OrderController::class, 'move'])->name('orders.move');
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::get('/orders/{id}/bill', [OrderController::class, 'bill'])->name('orders.bill');
        Route::post('/orders/{id}/payments', [OrderController::class, 'pay'])->name('orders.payments.store');
        Route::post('/order-items/{id}/void', [OrderController::class, 'void'])->name('items.void');

        Route::get('/kitchen-tickets', [OrderController::class, 'tickets'])->name('kitchen-tickets.index');
        Route::post('/kitchen-tickets/{id}/status', [OrderController::class, 'advanceTicket'])->name('kitchen-tickets.advance');
    });
