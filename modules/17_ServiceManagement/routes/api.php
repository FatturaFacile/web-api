<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceManagement\Http\Controllers\HelpdeskTicketController;
use Modules\ServiceManagement\Http\Controllers\ITSMController;
use Modules\ServiceManagement\Http\Controllers\PortalHelpdeskController;
use Modules\ServiceManagement\Http\Controllers\ServiceContractController;
use Modules\ServiceManagement\Http\Controllers\TicketController;
use Modules\ServiceManagement\Http\Controllers\TicketConversationController;
use Modules\ServiceManagement\Http\Controllers\WorkOrderController;

// Customer portal: the magic-link token resolves the tenant and the customer.
Route::prefix('api/v1/portal/helpdesk')
    ->middleware(['api', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/{token}/tickets', [PortalHelpdeskController::class, 'index'])->name('api.v1.portal.helpdesk.tickets.index');
        Route::post('/{token}/tickets', [PortalHelpdeskController::class, 'store'])->name('api.v1.portal.helpdesk.tickets.store');
        Route::get('/{token}/tickets/{ticket}', [PortalHelpdeskController::class, 'show'])->name('api.v1.portal.helpdesk.tickets.show');
        Route::post('/{token}/tickets/{ticket}/reply', [PortalHelpdeskController::class, 'reply'])->name('api.v1.portal.helpdesk.tickets.reply');
        Route::get('/{token}/tickets/{ticket}/attachments/{attachment}', [PortalHelpdeskController::class, 'downloadAttachment'])->name('api.v1.portal.helpdesk.attachments.show');
    });

Route::prefix('api/v1/esm')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:service'])
    ->group(function (): void {
        // Tickets & Helpdesk Routes
        Route::get('/tickets', [TicketController::class, 'index'])->name('api.v1.esm.tickets.index');
        Route::post('/tickets', [TicketController::class, 'store'])->name('api.v1.esm.tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('api.v1.esm.tickets.show');
        Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('api.v1.esm.tickets.assign');
        Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->name('api.v1.esm.tickets.resolve');
        Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('api.v1.esm.tickets.close');

        // Helpdesk: lifecycle
        Route::get('/helpdesk/priority-matrix', [HelpdeskTicketController::class, 'priorityMatrix'])->name('api.v1.esm.helpdesk.priority-matrix');
        Route::patch('/tickets/{ticket}', [HelpdeskTicketController::class, 'update'])->name('api.v1.esm.tickets.update');
        Route::post('/tickets/{ticket}/status', [HelpdeskTicketController::class, 'changeStatus'])->name('api.v1.esm.tickets.status');
        Route::post('/tickets/{ticket}/reopen', [HelpdeskTicketController::class, 'reopen'])->name('api.v1.esm.tickets.reopen');
        Route::post('/tickets/{ticket}/merge', [HelpdeskTicketController::class, 'merge'])->name('api.v1.esm.tickets.merge');
        Route::post('/tickets/{ticket}/split', [HelpdeskTicketController::class, 'split'])->name('api.v1.esm.tickets.split');
        Route::get('/tickets/{ticket}/watchers', [HelpdeskTicketController::class, 'watchers'])->name('api.v1.esm.tickets.watchers.index');
        Route::post('/tickets/{ticket}/watchers', [HelpdeskTicketController::class, 'addWatcher'])->name('api.v1.esm.tickets.watchers.store');
        Route::delete('/tickets/{ticket}/watchers/{user}', [HelpdeskTicketController::class, 'removeWatcher'])->name('api.v1.esm.tickets.watchers.destroy');
        Route::get('/tickets/{ticket}/activities', [HelpdeskTicketController::class, 'activities'])->name('api.v1.esm.tickets.activities');

        // Helpdesk: conversation
        Route::get('/tickets/{ticket}/messages', [TicketConversationController::class, 'index'])->name('api.v1.esm.tickets.messages.index');
        Route::post('/tickets/{ticket}/messages', [TicketConversationController::class, 'store'])->name('api.v1.esm.tickets.messages.store');
        Route::post('/tickets/{ticket}/read', [TicketConversationController::class, 'markRead'])->name('api.v1.esm.tickets.read');
        Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketConversationController::class, 'downloadAttachment'])->name('api.v1.esm.tickets.attachments.show');

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
