<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceManagement\Http\Controllers\EquipmentPassportController;
use Modules\ServiceManagement\Http\Controllers\ITSMController;
use Modules\ServiceManagement\Http\Controllers\LoanerController;
use Modules\ServiceManagement\Http\Controllers\PublicRmaController;
use Modules\ServiceManagement\Http\Controllers\RmaKanbanController;
use Modules\ServiceManagement\Http\Controllers\RmaOrderController;
use Modules\ServiceManagement\Http\Controllers\ServiceContractController;
use Modules\ServiceManagement\Http\Controllers\TicketController;
use Modules\ServiceManagement\Http\Controllers\VendorClaimController;
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

        // Warranty: Serialized Equipment Passports (GH-396)
        Route::get('/equipment-passports', [EquipmentPassportController::class, 'index'])->name('api.v1.esm.equipment-passports.index');
        Route::post('/equipment-passports', [EquipmentPassportController::class, 'store'])->name('api.v1.esm.equipment-passports.store');
        Route::get('/equipment-passports/lookup', [EquipmentPassportController::class, 'lookup'])->name('api.v1.esm.equipment-passports.lookup');
        Route::get('/equipment-passports/{passport}', [EquipmentPassportController::class, 'show'])->name('api.v1.esm.equipment-passports.show')->whereUuid('passport');
        Route::put('/equipment-passports/{passport}', [EquipmentPassportController::class, 'update'])->name('api.v1.esm.equipment-passports.update')->whereUuid('passport');
        Route::post('/equipment-passports/{passport}/extensions', [EquipmentPassportController::class, 'storeExtension'])->name('api.v1.esm.equipment-passports.extensions.store')->whereUuid('passport');
        Route::get('/equipment-passports/{passport}/genealogy', [EquipmentPassportController::class, 'genealogy'])->name('api.v1.esm.equipment-passports.genealogy')->whereUuid('passport');

        // Depot Repair RMA (GH-396) - board must be registered before {rmaOrder}
        Route::get('/rma-orders/board', RmaKanbanController::class)->name('api.v1.esm.rma-orders.board');
        Route::get('/rma-orders', [RmaOrderController::class, 'index'])->name('api.v1.esm.rma-orders.index');
        Route::post('/rma-orders', [RmaOrderController::class, 'store'])->name('api.v1.esm.rma-orders.store');
        Route::get('/rma-orders/{rmaOrder}', [RmaOrderController::class, 'show'])->name('api.v1.esm.rma-orders.show')->whereUuid('rmaOrder');
        Route::get('/rma-orders/{rmaOrder}/label', [RmaOrderController::class, 'label'])->name('api.v1.esm.rma-orders.label')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/tracking-token', [RmaOrderController::class, 'regenerateTrackingToken'])->name('api.v1.esm.rma-orders.tracking-token')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/receive', [RmaOrderController::class, 'receive'])->name('api.v1.esm.rma-orders.receive')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/diagnosis', [RmaOrderController::class, 'diagnosis'])->name('api.v1.esm.rma-orders.diagnosis')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/estimate/approve', [RmaOrderController::class, 'approveEstimate'])->name('api.v1.esm.rma-orders.estimate.approve')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/estimate/decline', [RmaOrderController::class, 'declineEstimate'])->name('api.v1.esm.rma-orders.estimate.decline')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/estimate/resend', [RmaOrderController::class, 'resendEstimate'])->name('api.v1.esm.rma-orders.estimate.resend')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/parts-received', [RmaOrderController::class, 'partsReceived'])->name('api.v1.esm.rma-orders.parts-received')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/complete-repair', [RmaOrderController::class, 'completeRepair'])->name('api.v1.esm.rma-orders.complete-repair')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/return', [RmaOrderController::class, 'returnToCustomer'])->name('api.v1.esm.rma-orders.return')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/close', [RmaOrderController::class, 'close'])->name('api.v1.esm.rma-orders.close')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/cancel', [RmaOrderController::class, 'cancel'])->name('api.v1.esm.rma-orders.cancel')->whereUuid('rmaOrder');
        Route::post('/rma-orders/{rmaOrder}/loaners', [LoanerController::class, 'store'])->name('api.v1.esm.rma-orders.loaners.store')->whereUuid('rmaOrder');

        // Loaner / repair-account register (GH-396)
        Route::get('/loaners', [LoanerController::class, 'index'])->name('api.v1.esm.loaners.index');
        Route::post('/loaners/{loaner}/return', [LoanerController::class, 'markReturned'])->name('api.v1.esm.loaners.return')->whereUuid('loaner');
        Route::post('/loaners/{loaner}/mark-lost', [LoanerController::class, 'markLost'])->name('api.v1.esm.loaners.mark-lost')->whereUuid('loaner');

        // Vendor warranty claim-backs (GH-396)
        Route::get('/vendor-claims', [VendorClaimController::class, 'index'])->name('api.v1.esm.vendor-claims.index');
        Route::get('/vendor-claims/{vendorClaim}', [VendorClaimController::class, 'show'])->name('api.v1.esm.vendor-claims.show')->whereUuid('vendorClaim');
        Route::patch('/vendor-claims/{vendorClaim}', [VendorClaimController::class, 'update'])->name('api.v1.esm.vendor-claims.update')->whereUuid('vendorClaim');
        Route::post('/vendor-claims/{vendorClaim}/transition', [VendorClaimController::class, 'transition'])->name('api.v1.esm.vendor-claims.transition')->whereUuid('vendorClaim');
    });

// Public, token-authenticated RMA endpoints (GH-396): no Sanctum, tenant resolved from the RMA behind the token.
Route::prefix('api/v1/public/rma')
    ->middleware(['api', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('/estimates/{token}', [PublicRmaController::class, 'showEstimate'])->name('api.v1.public.rma.estimates.show');
        Route::post('/estimates/{token}/accept', [PublicRmaController::class, 'acceptEstimate'])->name('api.v1.public.rma.estimates.accept');
        Route::post('/estimates/{token}/decline', [PublicRmaController::class, 'declineEstimate'])->name('api.v1.public.rma.estimates.decline');
        Route::get('/tracking', [PublicRmaController::class, 'tracking'])->name('api.v1.public.rma.tracking');
    });
