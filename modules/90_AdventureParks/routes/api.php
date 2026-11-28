<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AdventureParks\Http\Controllers\DailyCheckController;
use Modules\AdventureParks\Http\Controllers\DashboardController;
use Modules\AdventureParks\Http\Controllers\LookupController;
use Modules\AdventureParks\Http\Controllers\PpeController;
use Modules\AdventureParks\Http\Controllers\PublicWaiverController;
use Modules\AdventureParks\Http\Controllers\SchoolBookingController;
use Modules\AdventureParks\Http\Controllers\SlotController;
use Modules\AdventureParks\Http\Controllers\TicketController;
use Modules\AdventureParks\Http\Controllers\WorkshopController;

/*
|--------------------------------------------------------------------------
| Adventure parks, climbing centres and educational farms (Mod 299 / Issue #545)
|--------------------------------------------------------------------------
| Timed slots on the harness stock per size, PPE register (UNI EN 15567 / EN 365), liability waiver signed from the booking link,
| dressing desk, educational farm workshops and school trips invoiced to the public administration (split payment, CIG / CUP).
*/
Route::prefix('api/v1/adventure-parks')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:adventure_parks'])
    ->group(function (): void {
        Route::get('lookups', [LookupController::class, 'index'])->name('adventure-parks.lookups');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('adventure-parks.dashboard');
        Route::get('slots/availability', [DashboardController::class, 'availability'])->name('adventure-parks.slots.availability');

        Route::get('slots', [SlotController::class, 'index'])->name('adventure-parks.slots.index');
        Route::post('slots', [SlotController::class, 'store'])->name('adventure-parks.slots.store');
        Route::put('slots/{id}', [SlotController::class, 'update'])->whereUuid('id')->name('adventure-parks.slots.update');
        Route::delete('slots/{id}', [SlotController::class, 'destroy'])->whereUuid('id')->name('adventure-parks.slots.destroy');

        Route::get('tickets', [TicketController::class, 'index'])->name('adventure-parks.tickets.index');
        Route::post('tickets', [TicketController::class, 'store'])->name('adventure-parks.tickets.store');
        Route::get('tickets/by-code/{codeOrToken}', [TicketController::class, 'byCode'])->name('adventure-parks.tickets.by-code');
        Route::get('tickets/{id}', [TicketController::class, 'show'])->whereUuid('id')->name('adventure-parks.tickets.show');
        Route::post('tickets/{id}/assign-ppe', [TicketController::class, 'assignPpe'])->whereUuid('id')->name('adventure-parks.tickets.assign-ppe');
        Route::post('tickets/{id}/start-course', [TicketController::class, 'startCourse'])->whereUuid('id')->name('adventure-parks.tickets.start-course');
        Route::post('tickets/{id}/no-show', [TicketController::class, 'noShow'])->whereUuid('id')->name('adventure-parks.tickets.no-show');
        Route::post('tickets/{id}/return-ppe', [TicketController::class, 'returnPpe'])->whereUuid('id')->name('adventure-parks.tickets.return-ppe');
        Route::post('tickets/{id}/briefing', [TicketController::class, 'briefing'])->whereUuid('id')->name('adventure-parks.tickets.briefing');

        Route::get('ppe', [PpeController::class, 'index'])->name('adventure-parks.ppe.index');
        Route::post('ppe', [PpeController::class, 'store'])->name('adventure-parks.ppe.store');
        Route::post('ppe/batch', [PpeController::class, 'batch'])->name('adventure-parks.ppe.batch');
        Route::get('ppe/alerts', [PpeController::class, 'alerts'])->name('adventure-parks.ppe.alerts');
        Route::get('ppe/{id}', [PpeController::class, 'show'])->whereUuid('id')->name('adventure-parks.ppe.show');
        Route::put('ppe/{id}', [PpeController::class, 'update'])->whereUuid('id')->name('adventure-parks.ppe.update');
        Route::delete('ppe/{id}', [PpeController::class, 'destroy'])->whereUuid('id')->name('adventure-parks.ppe.destroy');
        Route::get('ppe/{id}/inspections', [PpeController::class, 'inspections'])->whereUuid('id')->name('adventure-parks.ppe.inspections.index');
        Route::post('ppe/{id}/inspections', [PpeController::class, 'storeInspection'])->whereUuid('id')->name('adventure-parks.ppe.inspections.store');

        Route::get('courses/daily-opening-check', [DailyCheckController::class, 'show'])->name('adventure-parks.daily-check.show');
        Route::post('courses/daily-opening-check', [DailyCheckController::class, 'store'])->name('adventure-parks.daily-check.store');

        Route::get('workshops', [WorkshopController::class, 'index'])->name('adventure-parks.workshops.index');
        Route::post('workshops', [WorkshopController::class, 'store'])->name('adventure-parks.workshops.store');
        Route::put('workshops/{id}', [WorkshopController::class, 'update'])->whereUuid('id')->name('adventure-parks.workshops.update');
        Route::delete('workshops/{id}', [WorkshopController::class, 'destroy'])->whereUuid('id')->name('adventure-parks.workshops.destroy');

        Route::get('school-bookings', [SchoolBookingController::class, 'index'])->name('adventure-parks.school-bookings.index');
        Route::post('school-bookings', [SchoolBookingController::class, 'store'])->name('adventure-parks.school-bookings.store');
        Route::get('school-bookings/quote', [SchoolBookingController::class, 'quote'])->name('adventure-parks.school-bookings.quote');
        Route::get('school-bookings/{id}', [SchoolBookingController::class, 'show'])->whereUuid('id')->name('adventure-parks.school-bookings.show');
        Route::put('school-bookings/{id}', [SchoolBookingController::class, 'update'])->whereUuid('id')->name('adventure-parks.school-bookings.update');
        Route::delete('school-bookings/{id}', [SchoolBookingController::class, 'destroy'])->whereUuid('id')->name('adventure-parks.school-bookings.destroy');
        Route::post('school-bookings/{id}/confirm', [SchoolBookingController::class, 'confirm'])->whereUuid('id')->name('adventure-parks.school-bookings.confirm');
        Route::post('school-bookings/{id}/complete', [SchoolBookingController::class, 'complete'])->whereUuid('id')->name('adventure-parks.school-bookings.complete');
        Route::post('school-bookings/{id}/generate-fattura-pa', [SchoolBookingController::class, 'generateFatturaPa'])->whereUuid('id')->name('adventure-parks.school-bookings.generate-fattura-pa');
    });

// Waiver signed by the visitor from the link of the booking: the unguessable token of the ticket is the credential, the
// endpoints are throttled by IP. The kiosk of the park (authenticated) uses the very same endpoints.
Route::prefix('api/v1/adventure-parks/public')
    ->middleware(['api', 'locale', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('waiver/{token}', [PublicWaiverController::class, 'show'])->name('adventure-parks.public.waiver.show');
        Route::post('waiver/{token}/sign', [PublicWaiverController::class, 'sign'])->name('adventure-parks.public.waiver.sign');
        Route::get('size-recommendation', [PublicWaiverController::class, 'recommendation'])->name('adventure-parks.public.size-recommendation');
    });
