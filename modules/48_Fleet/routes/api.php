<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Fleet\Http\Controllers\AssignmentController;
use Modules\Fleet\Http\Controllers\DashboardController;
use Modules\Fleet\Http\Controllers\DeadlineController;
use Modules\Fleet\Http\Controllers\FuelEntryController;
use Modules\Fleet\Http\Controllers\IncidentController;
use Modules\Fleet\Http\Controllers\ScheduledServiceController;
use Modules\Fleet\Http\Controllers\ServiceRecordController;
use Modules\Fleet\Http\Controllers\VehicleController;

$uuid = '[0-9a-fA-F-]{36}';

/*
| Company vehicles. module.access:fleet lets every member read (GET) and only the roles with write access of
| config/permissions.php change anything; the policy keeps the records inside the company.
*/
Route::prefix('api/v1/fleet')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:fleet'])
    ->name('api.v1.fleet.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'summary'])->name('dashboard');

        Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::get('/vehicles/{id}', [VehicleController::class, 'show'])->name('vehicles.show');
        Route::put('/vehicles/{id}', [VehicleController::class, 'update'])->name('vehicles.update');
        Route::delete('/vehicles/{id}', [VehicleController::class, 'destroy'])->name('vehicles.destroy');
        Route::get('/vehicles/{id}/costs', [VehicleController::class, 'costs'])->name('vehicles.costs');

        Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
        Route::post('/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
        Route::get('/assignments/{id}', [AssignmentController::class, 'show'])->name('assignments.show');
        Route::put('/assignments/{id}', [AssignmentController::class, 'update'])->name('assignments.update');
        Route::post('/assignments/{id}/end', [AssignmentController::class, 'end'])->name('assignments.end');
        Route::delete('/assignments/{id}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');

        Route::get('/fuel-entries', [FuelEntryController::class, 'index'])->name('fuel-entries.index');
        Route::post('/fuel-entries', [FuelEntryController::class, 'store'])->name('fuel-entries.store');
        Route::get('/fuel-entries/{id}', [FuelEntryController::class, 'show'])->name('fuel-entries.show');
        Route::put('/fuel-entries/{id}', [FuelEntryController::class, 'update'])->name('fuel-entries.update');
        Route::delete('/fuel-entries/{id}', [FuelEntryController::class, 'destroy'])->name('fuel-entries.destroy');

        Route::get('/services', [ServiceRecordController::class, 'index'])->name('services.index');
        Route::post('/services', [ServiceRecordController::class, 'store'])->name('services.store');
        Route::get('/services/{id}', [ServiceRecordController::class, 'show'])->name('services.show');
        Route::put('/services/{id}', [ServiceRecordController::class, 'update'])->name('services.update');
        Route::delete('/services/{id}', [ServiceRecordController::class, 'destroy'])->name('services.destroy');

        Route::get('/scheduled-services', [ScheduledServiceController::class, 'index'])->name('scheduled-services.index');
        Route::post('/scheduled-services', [ScheduledServiceController::class, 'store'])->name('scheduled-services.store');
        Route::get('/scheduled-services/{id}', [ScheduledServiceController::class, 'show'])->name('scheduled-services.show');
        Route::put('/scheduled-services/{id}', [ScheduledServiceController::class, 'update'])->name('scheduled-services.update');
        Route::delete('/scheduled-services/{id}', [ScheduledServiceController::class, 'destroy'])->name('scheduled-services.destroy');

        Route::get('/deadlines', [DeadlineController::class, 'index'])->name('deadlines.index');
        Route::post('/deadlines', [DeadlineController::class, 'store'])->name('deadlines.store');
        Route::get('/deadlines/{id}', [DeadlineController::class, 'show'])->name('deadlines.show');
        Route::put('/deadlines/{id}', [DeadlineController::class, 'update'])->name('deadlines.update');
        Route::post('/deadlines/{id}/renew', [DeadlineController::class, 'renew'])->name('deadlines.renew');
        Route::delete('/deadlines/{id}', [DeadlineController::class, 'destroy'])->name('deadlines.destroy');

        Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
        Route::get('/incidents/{id}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::put('/incidents/{id}', [IncidentController::class, 'update'])->name('incidents.update');
        Route::post('/incidents/{id}/pay', [IncidentController::class, 'pay'])->name('incidents.pay');
        Route::delete('/incidents/{id}', [IncidentController::class, 'destroy'])->name('incidents.destroy');
    });
