<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Transportation\Http\Controllers\CarrierController;
use Modules\Transportation\Http\Controllers\DashboardController;
use Modules\Transportation\Http\Controllers\FreightInvoiceController;
use Modules\Transportation\Http\Controllers\QuoteComparisonController;
use Modules\Transportation\Http\Controllers\QuoteController;
use Modules\Transportation\Http\Controllers\RateController;
use Modules\Transportation\Http\Controllers\TariffBandController;
use Modules\Transportation\Http\Controllers\TariffTableController;
use Modules\Transportation\Http\Controllers\TrackingEventController;
use Modules\Transportation\Http\Controllers\TransportOrderActionController;
use Modules\Transportation\Http\Controllers\TransportOrderController;
use Modules\Transportation\Http\Controllers\TripActionController;
use Modules\Transportation\Http\Controllers\TripController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Transportation management. module.access:transportation lets every member read (GET) and only the roles with write access of config/permissions.php change anything; the policy keeps the records inside the company.
*/
Route::prefix('api/v1/transportation')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:transportation'])
    ->name('api.v1.transportation.')
    ->where(['id' => $uuid, 'orderId' => $uuid, 'stopId' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');
        Route::post('/rates/calculate', [RateController::class, 'calculate'])->name('rates.calculate');

        Route::get('/carriers', [CarrierController::class, 'index'])->name('carriers.index');
        Route::post('/carriers', [CarrierController::class, 'store'])->name('carriers.store');
        Route::get('/carriers/{id}', [CarrierController::class, 'show'])->name('carriers.show');
        Route::put('/carriers/{id}', [CarrierController::class, 'update'])->name('carriers.update');
        Route::delete('/carriers/{id}', [CarrierController::class, 'destroy'])->name('carriers.destroy');

        Route::get('/tariff-tables', [TariffTableController::class, 'index'])->name('tariff-tables.index');
        Route::post('/tariff-tables', [TariffTableController::class, 'store'])->name('tariff-tables.store');
        Route::get('/tariff-tables/{id}', [TariffTableController::class, 'show'])->name('tariff-tables.show');
        Route::put('/tariff-tables/{id}', [TariffTableController::class, 'update'])->name('tariff-tables.update');
        Route::delete('/tariff-tables/{id}', [TariffTableController::class, 'destroy'])->name('tariff-tables.destroy');

        Route::get('/tariff-bands', [TariffBandController::class, 'index'])->name('tariff-bands.index');
        Route::post('/tariff-bands', [TariffBandController::class, 'store'])->name('tariff-bands.store');
        Route::get('/tariff-bands/{id}', [TariffBandController::class, 'show'])->name('tariff-bands.show');
        Route::put('/tariff-bands/{id}', [TariffBandController::class, 'update'])->name('tariff-bands.update');
        Route::delete('/tariff-bands/{id}', [TariffBandController::class, 'destroy'])->name('tariff-bands.destroy');

        Route::get('/trips', [TripController::class, 'index'])->name('trips.index');
        Route::post('/trips', [TripController::class, 'store'])->name('trips.store');
        Route::get('/trips/{id}', [TripController::class, 'show'])->name('trips.show');
        Route::put('/trips/{id}', [TripController::class, 'update'])->name('trips.update');
        Route::delete('/trips/{id}', [TripController::class, 'destroy'])->name('trips.destroy');
        Route::post('/trips/{id}/orders', [TripActionController::class, 'addOrders'])->name('trips.orders.add');
        Route::delete('/trips/{id}/orders/{orderId}', [TripActionController::class, 'removeOrder'])->name('trips.orders.remove');
        Route::put('/trips/{id}/stops/sequence', [TripActionController::class, 'reorderStops'])->name('trips.stops.sequence');
        Route::post('/trips/{id}/stops/{stopId}/complete', [TripActionController::class, 'completeStop'])->name('trips.stops.complete');
        Route::post('/trips/{id}/dispatch', [TripActionController::class, 'dispatch'])->name('trips.dispatch');
        Route::post('/trips/{id}/start', [TripActionController::class, 'start'])->name('trips.start');
        Route::post('/trips/{id}/complete', [TripActionController::class, 'complete'])->name('trips.complete');
        Route::post('/trips/{id}/cancel', [TripActionController::class, 'cancel'])->name('trips.cancel');

        Route::get('/transport-orders', [TransportOrderController::class, 'index'])->name('transport-orders.index');
        Route::post('/transport-orders', [TransportOrderController::class, 'store'])->name('transport-orders.store');
        Route::get('/transport-orders/{id}', [TransportOrderController::class, 'show'])->name('transport-orders.show');
        Route::put('/transport-orders/{id}', [TransportOrderController::class, 'update'])->name('transport-orders.update');
        Route::delete('/transport-orders/{id}', [TransportOrderController::class, 'destroy'])->name('transport-orders.destroy');
        Route::post('/transport-orders/{id}/plan', [TransportOrderActionController::class, 'plan'])->name('transport-orders.plan');
        Route::post('/transport-orders/{id}/assign-carrier', [TransportOrderActionController::class, 'assignCarrier'])->name('transport-orders.assign-carrier');
        Route::post('/transport-orders/{id}/cancel', [TransportOrderActionController::class, 'cancel'])->name('transport-orders.cancel');

        Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::get('/quotes/{id}', [QuoteController::class, 'show'])->name('quotes.show');
        Route::delete('/quotes/{id}', [QuoteController::class, 'destroy'])->name('quotes.destroy');
        Route::post('/quotes', [QuoteComparisonController::class, 'store'])->name('quotes.store');
        Route::post('/quotes/{id}/award', [QuoteComparisonController::class, 'award'])->name('quotes.award');

        Route::get('/tracking-events', [TrackingEventController::class, 'index'])->name('tracking-events.index');
        Route::post('/tracking-events', [TrackingEventController::class, 'store'])->name('tracking-events.store');
        Route::get('/tracking-events/{id}', [TrackingEventController::class, 'show'])->name('tracking-events.show');

        Route::get('/freight-invoices', [FreightInvoiceController::class, 'index'])->name('freight-invoices.index');
        Route::post('/freight-invoices', [FreightInvoiceController::class, 'store'])->name('freight-invoices.store');
        Route::get('/freight-invoices/{id}', [FreightInvoiceController::class, 'show'])->name('freight-invoices.show');
        Route::put('/freight-invoices/{id}', [FreightInvoiceController::class, 'update'])->name('freight-invoices.update');
        Route::delete('/freight-invoices/{id}', [FreightInvoiceController::class, 'destroy'])->name('freight-invoices.destroy');
        Route::post('/freight-invoices/{id}/audit', [FreightInvoiceController::class, 'audit'])->name('freight-invoices.audit');
        Route::post('/freight-invoices/{id}/dispute', [FreightInvoiceController::class, 'dispute'])->name('freight-invoices.dispute');
        Route::post('/freight-invoices/{id}/resolve-dispute', [FreightInvoiceController::class, 'resolveDispute'])->name('freight-invoices.resolve-dispute');
    });
