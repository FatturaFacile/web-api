<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceManagement\Http\Controllers\ITSMController;
use Modules\ServiceManagement\Http\Controllers\ServiceContractController;
use Modules\ServiceManagement\Http\Controllers\TicketController;
use Modules\ServiceManagement\Http\Controllers\WorkOrderController;

Route::prefix('api/v1/esm')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // Tickets & Helpdesk Routes
        Route::get('/tickets', [TicketController::class, 'index'])->name('api.v1.esm.tickets.index');
        Route::post('/tickets', [TicketController::class, 'store'])->name('api.v1.esm.tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('api.v1.esm.tickets.show');
        Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('api.v1.esm.tickets.assign');
        Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->name('api.v1.esm.tickets.resolve');
        Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('api.v1.esm.tickets.close');

        // ITSM: Incidents, Problems, Changes
        Route::get('/incidents', [ITSMController::class, 'incidents'])->name('api.v1.esm.incidents.index');
        Route::post('/incidents', [ITSMController::class, 'storeIncident'])->name('api.v1.esm.incidents.store');
        Route::post('/incidents/{incident}/resolve', [ITSMController::class, 'resolveIncident'])->name('api.v1.esm.incidents.resolve');
        Route::get('/problems', [ITSMController::class, 'problems'])->name('api.v1.esm.problems.index');
        Route::get('/changes', [ITSMController::class, 'changes'])->name('api.v1.esm.changes.index');
        Route::post('/changes', [ITSMController::class, 'storeChange'])->name('api.v1.esm.changes.store');
        Route::post('/changes/{change}/approve', [ITSMController::class, 'approveChange'])->name('api.v1.esm.changes.approve');

        // Field Service & Work Orders
        Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('api.v1.esm.work-orders.index');
        Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('api.v1.esm.work-orders.store');
        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('api.v1.esm.work-orders.show');
        Route::post('/work-orders/{workOrder}/dispatch', [WorkOrderController::class, 'dispatch'])->name('api.v1.esm.work-orders.dispatch');
        Route::post('/work-orders/{workOrder}/schedule', [WorkOrderController::class, 'schedule'])->name('api.v1.esm.work-orders.schedule');
        Route::post('/work-orders/{workOrder}/record-visit', [WorkOrderController::class, 'recordVisit'])->name('api.v1.esm.work-orders.record-visit');
        Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('api.v1.esm.work-orders.complete');

        // Service Contracts & CSAT
        Route::get('/service-contracts', [ServiceContractController::class, 'index'])->name('api.v1.esm.service-contracts.index');
        Route::get('/service-contracts/{serviceContract}', [ServiceContractController::class, 'show'])->name('api.v1.esm.service-contracts.show');
        Route::post('/csat', [ServiceContractController::class, 'submitCsat'])->name('api.v1.esm.csat.store');
    });
