<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Hospitality\Http\Controllers\FloorController;
use Modules\Hospitality\Http\Controllers\GroupBlockController;
use Modules\Hospitality\Http\Controllers\HousekeepingController;
use Modules\Hospitality\Http\Controllers\LodgingCatalogController;
use Modules\Hospitality\Http\Controllers\OrderController;
use Modules\Hospitality\Http\Controllers\ReservationController;
use Modules\Hospitality\Http\Controllers\StayController;

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

        Route::get('/room-types', [LodgingCatalogController::class, 'roomTypes'])->name('room-types.index');
        Route::post('/room-types', [LodgingCatalogController::class, 'storeRoomType'])->name('room-types.store');
        Route::put('/room-types/{id}', [LodgingCatalogController::class, 'updateRoomType'])->name('room-types.update');
        Route::get('/accommodations', [LodgingCatalogController::class, 'accommodations'])->name('accommodations.index');
        Route::post('/accommodations', [LodgingCatalogController::class, 'storeAccommodation'])->name('accommodations.store');
        Route::put('/accommodations/{id}', [LodgingCatalogController::class, 'updateAccommodation'])->name('accommodations.update');
        Route::get('/rate-plans', [LodgingCatalogController::class, 'ratePlans'])->name('rate-plans.index');
        Route::post('/rate-plans', [LodgingCatalogController::class, 'storeRatePlan'])->name('rate-plans.store');
        Route::put('/rate-plans/{id}', [LodgingCatalogController::class, 'updateRatePlan'])->name('rate-plans.update');
        Route::get('/stay-availability', [LodgingCatalogController::class, 'availability'])->name('stays.availability');
        Route::get('/stay-quote', [LodgingCatalogController::class, 'quote'])->name('stays.quote');

        Route::get('/stays', [StayController::class, 'index'])->name('stays.index');
        Route::post('/stays', [StayController::class, 'store'])->name('stays.store');
        Route::get('/stays/{id}', [StayController::class, 'show'])->name('stays.show');
        Route::post('/stays/{id}/check-in', [StayController::class, 'checkIn'])->name('stays.check-in');
        Route::post('/stays/{id}/check-out', [StayController::class, 'checkOut'])->name('stays.check-out');
        Route::post('/stays/{id}/cancel', [StayController::class, 'cancel'])->name('stays.cancel');
        Route::post('/stays/{id}/no-show', [StayController::class, 'noShow'])->name('stays.no-show');
        Route::post('/stays/{id}/charges', [StayController::class, 'storeCharge'])->name('stays.charges.store');
        Route::post('/stays/{id}/payments', [StayController::class, 'storePayment'])->name('stays.payments.store');
        Route::post('/folio-entries/{id}/void', [StayController::class, 'voidEntry'])->name('folio-entries.void');
        Route::get('/availability-grid', [StayController::class, 'availabilityGrid'])->name('stays.availability-grid');
        Route::get('/stays/{id}/folio-statement', [StayController::class, 'folioStatement'])->name('stays.folio-statement');
        Route::post('/stays/{id}/invoice', [StayController::class, 'invoice'])->name('stays.invoice');

        Route::get('/group-blocks', [GroupBlockController::class, 'index'])->name('group-blocks.index');
        Route::post('/group-blocks', [GroupBlockController::class, 'store'])->name('group-blocks.store');
        Route::get('/group-blocks/{id}', [GroupBlockController::class, 'show'])->name('group-blocks.show');
        Route::put('/group-blocks/{id}', [GroupBlockController::class, 'update'])->name('group-blocks.update');
        Route::post('/group-blocks/{id}/dates', [GroupBlockController::class, 'changeDates'])->name('group-blocks.dates');
        Route::post('/group-blocks/{id}/release', [GroupBlockController::class, 'release'])->name('group-blocks.release');
        Route::post('/group-blocks/{id}/cancel', [GroupBlockController::class, 'cancel'])->name('group-blocks.cancel');
        Route::post('/group-blocks/{id}/stays', [GroupBlockController::class, 'storeStay'])->name('group-blocks.stays.store');

        Route::get('/housekeeping-tasks', [HousekeepingController::class, 'index'])->name('housekeeping-tasks.index');
        Route::post('/housekeeping-tasks', [HousekeepingController::class, 'store'])->name('housekeeping-tasks.store');
        Route::post('/housekeeping-tasks/{id}/start', [HousekeepingController::class, 'start'])->name('housekeeping-tasks.start');
        Route::post('/housekeeping-tasks/{id}/complete', [HousekeepingController::class, 'complete'])->name('housekeeping-tasks.complete');
    });
