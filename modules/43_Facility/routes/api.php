<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Facility\Http\Controllers\BookingController;
use Modules\Facility\Http\Controllers\BookingRuleController;
use Modules\Facility\Http\Controllers\BookingSeriesController;
use Modules\Facility\Http\Controllers\BuildingController;
use Modules\Facility\Http\Controllers\InspectionController;
use Modules\Facility\Http\Controllers\InspectionTemplateController;
use Modules\Facility\Http\Controllers\MeterController;
use Modules\Facility\Http\Controllers\OverviewController;
use Modules\Facility\Http\Controllers\ServiceRequestController;
use Modules\Facility\Http\Controllers\SpaceController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Facility management and workplace. module.access:facility lets every member read and write; the policy reserves the
| building register, the booking rules, the inspection templates and the meters to facility managers, and the work on
| service requests, inspections and meter readings to operators.
*/
Route::prefix('api/v1/facility')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:facility'])
    ->name('api.v1.facility.')
    ->where(['id' => $uuid, 'readingId' => $uuid])
    ->group(function (): void {
        Route::get('/overview', [OverviewController::class, 'show'])->name('overview');

        Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
        Route::post('/buildings', [BuildingController::class, 'store'])->name('buildings.store');
        Route::get('/buildings/{id}', [BuildingController::class, 'show'])->name('buildings.show');
        Route::put('/buildings/{id}', [BuildingController::class, 'update'])->name('buildings.update');
        Route::delete('/buildings/{id}', [BuildingController::class, 'destroy'])->name('buildings.destroy');
        Route::get('/buildings/{id}/tree', [SpaceController::class, 'tree'])->name('buildings.tree');

        Route::get('/spaces', [SpaceController::class, 'index'])->name('spaces.index');
        Route::get('/spaces/available', [SpaceController::class, 'available'])->name('spaces.available');
        Route::post('/spaces', [SpaceController::class, 'store'])->name('spaces.store');
        Route::get('/spaces/{id}', [SpaceController::class, 'show'])->name('spaces.show');
        Route::put('/spaces/{id}', [SpaceController::class, 'update'])->name('spaces.update');
        Route::delete('/spaces/{id}', [SpaceController::class, 'destroy'])->name('spaces.destroy');

        Route::get('/booking-rules', [BookingRuleController::class, 'index'])->name('booking-rules.index');
        Route::put('/booking-rules/{kind}', [BookingRuleController::class, 'update'])->name('booking-rules.update');

        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::post('/bookings/check', [BookingController::class, 'check'])->name('bookings.check');
        Route::get('/bookings/{id}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('/bookings/{id}/check-in', [BookingController::class, 'checkIn'])->name('bookings.check-in');
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');

        Route::get('/booking-series', [BookingSeriesController::class, 'index'])->name('booking-series.index');
        Route::post('/booking-series', [BookingSeriesController::class, 'store'])->name('booking-series.store');
        Route::get('/booking-series/{id}', [BookingSeriesController::class, 'show'])->name('booking-series.show');
        Route::post('/booking-series/{id}/cancel', [BookingSeriesController::class, 'cancel'])->name('booking-series.cancel');

        Route::get('/service-requests', [ServiceRequestController::class, 'index'])->name('service-requests.index');
        Route::get('/service-requests/board', [ServiceRequestController::class, 'board'])->name('service-requests.board');
        Route::post('/service-requests', [ServiceRequestController::class, 'store'])->name('service-requests.store');
        Route::get('/service-requests/{id}', [ServiceRequestController::class, 'show'])->name('service-requests.show');
        Route::put('/service-requests/{id}', [ServiceRequestController::class, 'update'])->name('service-requests.update');
        Route::post('/service-requests/{id}/assign', [ServiceRequestController::class, 'assign'])->name('service-requests.assign');
        Route::post('/service-requests/{id}/start', [ServiceRequestController::class, 'start'])->name('service-requests.start');
        Route::post('/service-requests/{id}/hold', [ServiceRequestController::class, 'hold'])->name('service-requests.hold');
        Route::post('/service-requests/{id}/resolve', [ServiceRequestController::class, 'resolve'])->name('service-requests.resolve');
        Route::post('/service-requests/{id}/close', [ServiceRequestController::class, 'close'])->name('service-requests.close');
        Route::post('/service-requests/{id}/reopen', [ServiceRequestController::class, 'reopen'])->name('service-requests.reopen');
        Route::post('/service-requests/{id}/cancel', [ServiceRequestController::class, 'cancel'])->name('service-requests.cancel');
        Route::post('/service-requests/{id}/link-work-order', [ServiceRequestController::class, 'linkWorkOrder'])->name('service-requests.link-work-order');
        Route::post('/service-requests/{id}/raise-work-order', [ServiceRequestController::class, 'raiseWorkOrder'])->name('service-requests.raise-work-order');
        Route::get('/service-requests/{id}/comments', [ServiceRequestController::class, 'comments'])->name('service-requests.comments.index');
        Route::post('/service-requests/{id}/comments', [ServiceRequestController::class, 'addComment'])->name('service-requests.comments.store');

        Route::get('/inspection-templates', [InspectionTemplateController::class, 'index'])->name('inspection-templates.index');
        Route::post('/inspection-templates', [InspectionTemplateController::class, 'store'])->name('inspection-templates.store');
        Route::post('/inspection-templates/generate', [InspectionTemplateController::class, 'generate'])->name('inspection-templates.generate');
        Route::get('/inspection-templates/{id}', [InspectionTemplateController::class, 'show'])->name('inspection-templates.show');
        Route::put('/inspection-templates/{id}', [InspectionTemplateController::class, 'update'])->name('inspection-templates.update');
        Route::delete('/inspection-templates/{id}', [InspectionTemplateController::class, 'destroy'])->name('inspection-templates.destroy');

        Route::get('/inspections', [InspectionController::class, 'index'])->name('inspections.index');
        Route::get('/inspections/{id}', [InspectionController::class, 'show'])->name('inspections.show');
        Route::post('/inspections/{id}/start', [InspectionController::class, 'start'])->name('inspections.start');
        Route::post('/inspections/{id}/complete', [InspectionController::class, 'complete'])->name('inspections.complete');
        Route::post('/inspections/{id}/cancel', [InspectionController::class, 'cancel'])->name('inspections.cancel');

        Route::get('/meters', [MeterController::class, 'index'])->name('meters.index');
        Route::get('/meters/summary', [MeterController::class, 'summary'])->name('meters.summary');
        Route::post('/meters', [MeterController::class, 'store'])->name('meters.store');
        Route::get('/meters/{id}', [MeterController::class, 'show'])->name('meters.show');
        Route::put('/meters/{id}', [MeterController::class, 'update'])->name('meters.update');
        Route::delete('/meters/{id}', [MeterController::class, 'destroy'])->name('meters.destroy');
        Route::get('/meters/{id}/readings', [MeterController::class, 'readings'])->name('meters.readings.index');
        Route::post('/meters/{id}/readings', [MeterController::class, 'recordReading'])->name('meters.readings.store');
        Route::delete('/meters/{id}/readings/{readingId}', [MeterController::class, 'deleteReading'])->name('meters.readings.destroy');
    });
