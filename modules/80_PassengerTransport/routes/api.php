<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\PassengerTransport\Http\Controllers\BillingController;
use Modules\PassengerTransport\Http\Controllers\BookingController;
use Modules\PassengerTransport\Http\Controllers\ContractController;
use Modules\PassengerTransport\Http\Controllers\DispatchController;
use Modules\PassengerTransport\Http\Controllers\FleetController;
use Modules\PassengerTransport\Http\Controllers\LookupController;
use Modules\PassengerTransport\Http\Controllers\ServiceSheetController;
use Modules\PassengerTransport\Http\Controllers\TachographController;

/*
|--------------------------------------------------------------------------
| Passenger transport: NCC, taxi, GT coach and school bus (Mod 176 / Issue #422)
|--------------------------------------------------------------------------
| Bookings with flight tracking and the cross-border kilometre split (CH-IT), dispatch with OLR 1 / OLR 2 / Reg. (EC) 561/2006
| checks, the NCC electronic service sheet (FDSE) and EU Interbus waybill, the tachograph (.DDD) archive, hotel concierge and
| school bus contracts, the service exchange between carriers and the month-end invoicing with the VAT split.
*/
Route::prefix('api/v1/passenger-transport')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:passenger_transport'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'lookups'])->name('passenger-transport.lookups');
        Route::get('lookups/drivers', [LookupController::class, 'drivers'])->name('passenger-transport.lookups.drivers');
        Route::get('lookups/contacts', [LookupController::class, 'contacts'])->name('passenger-transport.lookups.contacts');

        Route::get('dispatch/timeline', [DispatchController::class, 'timeline'])->name('passenger-transport.dispatch.timeline');

        Route::get('bookings', [BookingController::class, 'index'])->name('passenger-transport.bookings.index');
        Route::post('bookings', [BookingController::class, 'store'])->name('passenger-transport.bookings.store');
        Route::post('bookings/quote', [BookingController::class, 'quote'])->name('passenger-transport.bookings.quote');
        Route::get('bookings/{id}', [BookingController::class, 'show'])->whereUuid('id')->name('passenger-transport.bookings.show');
        Route::put('bookings/{id}', [BookingController::class, 'update'])->whereUuid('id')->name('passenger-transport.bookings.update');
        Route::delete('bookings/{id}', [BookingController::class, 'destroy'])->whereUuid('id')->name('passenger-transport.bookings.destroy');
        Route::post('bookings/{id}/transition', [BookingController::class, 'transition'])->whereUuid('id')->name('passenger-transport.bookings.transition');
        Route::post('bookings/{id}/assign', [BookingController::class, 'assign'])->whereUuid('id')->name('passenger-transport.bookings.assign');
        Route::post('bookings/{id}/track-flight', [BookingController::class, 'trackFlight'])->whereUuid('id')->name('passenger-transport.bookings.track-flight');
        Route::post('bookings/{id}/subcontract', [BookingController::class, 'subcontract'])->whereUuid('id')->name('passenger-transport.bookings.subcontract');
        Route::post('bookings/{id}/invoice', [BookingController::class, 'invoice'])->whereUuid('id')->name('passenger-transport.bookings.invoice');
        Route::post('bookings/{id}/welcome-logo', [BookingController::class, 'welcomeLogo'])->whereUuid('id')->name('passenger-transport.bookings.welcome-logo');
        Route::get('bookings/{id}/welcome-sign', [BookingController::class, 'welcomeSign'])->whereUuid('id')->name('passenger-transport.bookings.welcome-sign');
        Route::get('bookings/{id}/welcome-sign/pdf', [BookingController::class, 'welcomeSignPdf'])->whereUuid('id')->name('passenger-transport.bookings.welcome-sign.pdf');

        Route::get('bookings/{id}/service-sheets', [ServiceSheetController::class, 'index'])->whereUuid('id')->name('passenger-transport.service-sheets.index');
        Route::post('bookings/{id}/service-sheets', [ServiceSheetController::class, 'store'])->whereUuid('id')->name('passenger-transport.service-sheets.store');
        Route::post('bookings/{id}/service-sheets/generate', [ServiceSheetController::class, 'generate'])->whereUuid('id')->name('passenger-transport.service-sheets.generate');
        Route::get('service-sheets/{id}', [ServiceSheetController::class, 'show'])->whereUuid('id')->name('passenger-transport.service-sheets.show');
        Route::put('service-sheets/{id}', [ServiceSheetController::class, 'update'])->whereUuid('id')->name('passenger-transport.service-sheets.update');
        Route::post('service-sheets/{id}/close', [ServiceSheetController::class, 'close'])->whereUuid('id')->name('passenger-transport.service-sheets.close');
        Route::post('service-sheets/{id}/transmit', [ServiceSheetController::class, 'transmit'])->whereUuid('id')->name('passenger-transport.service-sheets.transmit');
        Route::get('service-sheets/{id}/pdf', [ServiceSheetController::class, 'pdf'])->whereUuid('id')->name('passenger-transport.service-sheets.pdf');
        Route::get('service-sheets/{id}/interbus-pdf', [ServiceSheetController::class, 'interbusPdf'])->whereUuid('id')->name('passenger-transport.service-sheets.interbus-pdf');

        Route::get('vehicles', [FleetController::class, 'vehicles'])->name('passenger-transport.vehicles.index');
        Route::post('vehicles', [FleetController::class, 'storeVehicle'])->name('passenger-transport.vehicles.store');
        Route::put('vehicles/{id}', [FleetController::class, 'updateVehicle'])->whereUuid('id')->name('passenger-transport.vehicles.update');
        Route::delete('vehicles/{id}', [FleetController::class, 'destroyVehicle'])->whereUuid('id')->name('passenger-transport.vehicles.destroy');

        Route::get('drivers', [FleetController::class, 'drivers'])->name('passenger-transport.drivers.index');
        Route::post('drivers', [FleetController::class, 'storeDriver'])->name('passenger-transport.drivers.store');
        Route::put('drivers/{id}', [FleetController::class, 'updateDriver'])->whereUuid('id')->name('passenger-transport.drivers.update');

        Route::get('tachograph/archives', [TachographController::class, 'index'])->name('passenger-transport.tachograph.archives.index');
        Route::post('tachograph/archives', [TachographController::class, 'store'])->name('passenger-transport.tachograph.archives.store');
        Route::get('tachograph/archives/{id}/download', [TachographController::class, 'download'])->whereUuid('id')->name('passenger-transport.tachograph.archives.download');
        Route::get('tachograph/infringements', [TachographController::class, 'infringements'])->name('passenger-transport.tachograph.infringements');

        Route::get('hotel-contracts', [ContractController::class, 'hotels'])->name('passenger-transport.hotel-contracts.index');
        Route::post('hotel-contracts', [ContractController::class, 'storeHotel'])->name('passenger-transport.hotel-contracts.store');
        Route::put('hotel-contracts/{id}', [ContractController::class, 'updateHotel'])->whereUuid('id')->name('passenger-transport.hotel-contracts.update');
        Route::delete('hotel-contracts/{id}', [ContractController::class, 'destroyHotel'])->whereUuid('id')->name('passenger-transport.hotel-contracts.destroy');
        Route::post('hotel-contracts/{id}/bookings', [ContractController::class, 'concierge'])->whereUuid('id')->name('passenger-transport.hotel-contracts.bookings');

        Route::get('school-contracts', [ContractController::class, 'schools'])->name('passenger-transport.school-contracts.index');
        Route::post('school-contracts', [ContractController::class, 'storeSchool'])->name('passenger-transport.school-contracts.store');
        Route::put('school-contracts/{id}', [ContractController::class, 'updateSchool'])->whereUuid('id')->name('passenger-transport.school-contracts.update');
        Route::delete('school-contracts/{id}', [ContractController::class, 'destroySchool'])->whereUuid('id')->name('passenger-transport.school-contracts.destroy');

        Route::get('billing/monthly-preview', [BillingController::class, 'preview'])->name('passenger-transport.billing.preview');
        Route::post('billing/monthly-invoices', [BillingController::class, 'invoiceMonth'])->name('passenger-transport.billing.invoices');
        Route::get('subcontract/ledger', [BillingController::class, 'ledger'])->name('passenger-transport.subcontract.ledger');
    });
