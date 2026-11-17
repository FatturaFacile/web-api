<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ServiceManagement\Http\Controllers\AgentToolsController;
use Modules\ServiceManagement\Http\Controllers\EquipmentPassportController;
use Modules\ServiceManagement\Http\Controllers\HelpdeskAutomationController;
use Modules\ServiceManagement\Http\Controllers\HelpdeskNotificationController;
use Modules\ServiceManagement\Http\Controllers\HelpdeskReportController;
use Modules\ServiceManagement\Http\Controllers\HelpdeskTicketController;
use Modules\ServiceManagement\Http\Controllers\ITSMController;
use Modules\ServiceManagement\Http\Controllers\KnowledgeBaseController;
use Modules\ServiceManagement\Http\Controllers\LoanerController;
use Modules\ServiceManagement\Http\Controllers\MaintenanceContractController;
use Modules\ServiceManagement\Http\Controllers\PortalCsatController;
use Modules\ServiceManagement\Http\Controllers\PortalHelpdeskController;
use Modules\ServiceManagement\Http\Controllers\PortalKnowledgeBaseController;
use Modules\ServiceManagement\Http\Controllers\PublicRmaController;
use Modules\ServiceManagement\Http\Controllers\RmaKanbanController;
use Modules\ServiceManagement\Http\Controllers\RmaOrderController;
use Modules\ServiceManagement\Http\Controllers\ServiceContractController;
use Modules\ServiceManagement\Http\Controllers\SupportMailboxController;
use Modules\ServiceManagement\Http\Controllers\TicketController;
use Modules\ServiceManagement\Http\Controllers\TicketConversationController;
use Modules\ServiceManagement\Http\Controllers\VendorClaimController;
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

// Satisfaction survey link: the secret token resolves the survey and its tenant.
Route::prefix('api/v1/portal/csat')
    ->middleware(['api', 'throttle:30,1'])
    ->group(function (): void {
        Route::get('/{token}', [PortalCsatController::class, 'show'])->name('api.v1.portal.csat.show');
        Route::post('/{token}', [PortalCsatController::class, 'answer'])->name('api.v1.portal.csat.answer');
    });

Route::prefix('api/v1/portal/kb')
    ->middleware(['api', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/{token}/categories', [PortalKnowledgeBaseController::class, 'categories'])->name('api.v1.portal.kb.categories');
        Route::get('/{token}/articles', [PortalKnowledgeBaseController::class, 'articles'])->name('api.v1.portal.kb.articles.index');
        Route::get('/{token}/articles/{id}', [PortalKnowledgeBaseController::class, 'show'])->name('api.v1.portal.kb.articles.show');
    });

Route::prefix('api/v1/esm')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:service'])
    ->group(function (): void {
        // Tickets & Helpdesk Routes
        Route::get('/tickets', [TicketController::class, 'index'])->name('api.v1.esm.tickets.index');
        Route::post('/tickets', [TicketController::class, 'store'])->name('api.v1.esm.tickets.store');
        Route::post('/tickets/bulk', [HelpdeskTicketController::class, 'bulk'])->name('api.v1.esm.tickets.bulk');
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

        // Helpdesk: routing, business hours, settings
        Route::post('/tickets/{ticket}/auto-assign', [HelpdeskTicketController::class, 'autoAssign'])->name('api.v1.esm.tickets.auto-assign');
        Route::get('/helpdesk/routing-rules', [HelpdeskAutomationController::class, 'routingRules'])->name('api.v1.esm.helpdesk.routing-rules.index');
        Route::post('/helpdesk/routing-rules', [HelpdeskAutomationController::class, 'storeRoutingRule'])->name('api.v1.esm.helpdesk.routing-rules.store');
        Route::patch('/helpdesk/routing-rules/{id}', [HelpdeskAutomationController::class, 'updateRoutingRule'])->name('api.v1.esm.helpdesk.routing-rules.update');
        Route::delete('/helpdesk/routing-rules/{id}', [HelpdeskAutomationController::class, 'destroyRoutingRule'])->name('api.v1.esm.helpdesk.routing-rules.destroy');
        Route::get('/helpdesk/business-hours', [HelpdeskAutomationController::class, 'businessHours'])->name('api.v1.esm.helpdesk.business-hours.index');
        Route::post('/helpdesk/business-hours', [HelpdeskAutomationController::class, 'storeBusinessHours'])->name('api.v1.esm.helpdesk.business-hours.store');
        Route::patch('/helpdesk/business-hours/{id}', [HelpdeskAutomationController::class, 'updateBusinessHours'])->name('api.v1.esm.helpdesk.business-hours.update');
        Route::delete('/helpdesk/business-hours/{id}', [HelpdeskAutomationController::class, 'destroyBusinessHours'])->name('api.v1.esm.helpdesk.business-hours.destroy');
        Route::get('/helpdesk/settings', [HelpdeskAutomationController::class, 'settings'])->name('api.v1.esm.helpdesk.settings.show');
        Route::put('/helpdesk/settings', [HelpdeskAutomationController::class, 'updateSettings'])->name('api.v1.esm.helpdesk.settings.update');
        Route::put('/helpdesk/teams/{id}/assignment', [HelpdeskAutomationController::class, 'updateTeamAssignment'])->name('api.v1.esm.helpdesk.teams.assignment');

        // Helpdesk: agent tools
        Route::get('/helpdesk/canned-responses', [AgentToolsController::class, 'cannedResponses'])->name('api.v1.esm.helpdesk.canned-responses.index');
        Route::post('/helpdesk/canned-responses', [AgentToolsController::class, 'storeCannedResponse'])->name('api.v1.esm.helpdesk.canned-responses.store');
        Route::patch('/helpdesk/canned-responses/{id}', [AgentToolsController::class, 'updateCannedResponse'])->name('api.v1.esm.helpdesk.canned-responses.update');
        Route::delete('/helpdesk/canned-responses/{id}', [AgentToolsController::class, 'destroyCannedResponse'])->name('api.v1.esm.helpdesk.canned-responses.destroy');
        Route::post('/tickets/{ticket}/canned-responses/{id}/render', [AgentToolsController::class, 'renderCannedResponse'])->name('api.v1.esm.tickets.canned-responses.render');
        Route::get('/helpdesk/macros', [AgentToolsController::class, 'macros'])->name('api.v1.esm.helpdesk.macros.index');
        Route::post('/helpdesk/macros', [AgentToolsController::class, 'storeMacro'])->name('api.v1.esm.helpdesk.macros.store');
        Route::patch('/helpdesk/macros/{id}', [AgentToolsController::class, 'updateMacro'])->name('api.v1.esm.helpdesk.macros.update');
        Route::delete('/helpdesk/macros/{id}', [AgentToolsController::class, 'destroyMacro'])->name('api.v1.esm.helpdesk.macros.destroy');
        Route::post('/tickets/{ticket}/macros/{id}/apply', [AgentToolsController::class, 'applyMacro'])->name('api.v1.esm.tickets.macros.apply');
        Route::get('/helpdesk/views', [AgentToolsController::class, 'views'])->name('api.v1.esm.helpdesk.views.index');
        Route::post('/helpdesk/views', [AgentToolsController::class, 'storeView'])->name('api.v1.esm.helpdesk.views.store');
        Route::patch('/helpdesk/views/{id}', [AgentToolsController::class, 'updateView'])->name('api.v1.esm.helpdesk.views.update');
        Route::delete('/helpdesk/views/{id}', [AgentToolsController::class, 'destroyView'])->name('api.v1.esm.helpdesk.views.destroy');

        // Helpdesk: notifications
        Route::get('/helpdesk/notification-preferences', [HelpdeskNotificationController::class, 'preferences'])->name('api.v1.esm.helpdesk.notification-preferences.show');
        Route::put('/helpdesk/notification-preferences', [HelpdeskNotificationController::class, 'updatePreferences'])->name('api.v1.esm.helpdesk.notification-preferences.update');
        Route::get('/helpdesk/notifications', [HelpdeskNotificationController::class, 'notifications'])->name('api.v1.esm.helpdesk.notifications.index');
        Route::post('/helpdesk/notifications/{id}/read', [HelpdeskNotificationController::class, 'markRead'])->name('api.v1.esm.helpdesk.notifications.read');

        // Helpdesk: reports
        Route::get('/helpdesk/reports', [HelpdeskReportController::class, 'show'])->name('api.v1.esm.helpdesk.reports');

        // Helpdesk: knowledge base
        Route::get('/kb/categories', [KnowledgeBaseController::class, 'categories'])->name('api.v1.esm.kb.categories.index');
        Route::post('/kb/categories', [KnowledgeBaseController::class, 'storeCategory'])->name('api.v1.esm.kb.categories.store');
        Route::patch('/kb/categories/{id}', [KnowledgeBaseController::class, 'updateCategory'])->name('api.v1.esm.kb.categories.update');
        Route::delete('/kb/categories/{id}', [KnowledgeBaseController::class, 'destroyCategory'])->name('api.v1.esm.kb.categories.destroy');
        Route::get('/kb/articles', [KnowledgeBaseController::class, 'articles'])->name('api.v1.esm.kb.articles.index');
        Route::post('/kb/articles', [KnowledgeBaseController::class, 'storeArticle'])->name('api.v1.esm.kb.articles.store');
        Route::get('/kb/articles/{id}', [KnowledgeBaseController::class, 'showArticle'])->name('api.v1.esm.kb.articles.show');
        Route::patch('/kb/articles/{id}', [KnowledgeBaseController::class, 'updateArticle'])->name('api.v1.esm.kb.articles.update');
        Route::delete('/kb/articles/{id}', [KnowledgeBaseController::class, 'destroyArticle'])->name('api.v1.esm.kb.articles.destroy');
        Route::get('/kb/articles/{id}/versions', [KnowledgeBaseController::class, 'versions'])->name('api.v1.esm.kb.articles.versions');
        Route::post('/kb/articles/{id}/versions/{version}/restore', [KnowledgeBaseController::class, 'restoreVersion'])->whereNumber('version')->name('api.v1.esm.kb.articles.versions.restore');
        Route::get('/tickets/{ticket}/knowledge-links', [KnowledgeBaseController::class, 'ticketLinks'])->name('api.v1.esm.tickets.knowledge-links.index');
        Route::post('/tickets/{ticket}/knowledge-links', [KnowledgeBaseController::class, 'linkTicket'])->name('api.v1.esm.tickets.knowledge-links.store');
        Route::delete('/tickets/{ticket}/knowledge-links/{id}', [KnowledgeBaseController::class, 'unlinkTicket'])->name('api.v1.esm.tickets.knowledge-links.destroy');

        // Helpdesk: support mailboxes
        Route::get('/helpdesk/mailboxes', [SupportMailboxController::class, 'index'])->name('api.v1.esm.helpdesk.mailboxes.index');
        Route::post('/helpdesk/mailboxes', [SupportMailboxController::class, 'store'])->name('api.v1.esm.helpdesk.mailboxes.store');
        Route::patch('/helpdesk/mailboxes/{id}', [SupportMailboxController::class, 'update'])->name('api.v1.esm.helpdesk.mailboxes.update');
        Route::delete('/helpdesk/mailboxes/{id}', [SupportMailboxController::class, 'destroy'])->name('api.v1.esm.helpdesk.mailboxes.destroy');
        Route::post('/helpdesk/mailboxes/{id}/poll', [SupportMailboxController::class, 'poll'])->name('api.v1.esm.helpdesk.mailboxes.poll');
        Route::get('/helpdesk/mailboxes/{id}/messages', [SupportMailboxController::class, 'messages'])->name('api.v1.esm.helpdesk.mailboxes.messages');

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
        Route::post('/work-orders/{workOrder}/parts', [WorkOrderController::class, 'consumePart'])->name('api.v1.esm.work-orders.parts');
        Route::post('/work-orders/{workOrder}/invoice', [WorkOrderController::class, 'invoice'])->name('api.v1.esm.work-orders.invoice');

        // Service Contracts & CSAT
        Route::get('/service-contracts', [ServiceContractController::class, 'index'])->name('api.v1.esm.service-contracts.index');
        Route::get('/service-contracts/{serviceContract}', [ServiceContractController::class, 'show'])->name('api.v1.esm.service-contracts.show');
        Route::post('/csat', [ServiceContractController::class, 'submitCsat'])->name('api.v1.esm.csat.store');

        // Recurring maintenance contracts, hour bank and installed base history (GH-197)
        Route::post('/service-contracts', [MaintenanceContractController::class, 'store'])->name('api.v1.esm.service-contracts.store');
        Route::put('/service-contracts/{serviceContract}', [MaintenanceContractController::class, 'update'])->name('api.v1.esm.service-contracts.update')->whereUuid('serviceContract');
        Route::get('/service-contracts/{serviceContract}/ledger', [MaintenanceContractController::class, 'ledger'])->name('api.v1.esm.service-contracts.ledger')->whereUuid('serviceContract');
        Route::post('/service-contracts/{serviceContract}/hours/top-up', [MaintenanceContractController::class, 'topUp'])->name('api.v1.esm.service-contracts.top-up')->whereUuid('serviceContract');
        Route::post('/service-contracts/{serviceContract}/hours/adjust', [MaintenanceContractController::class, 'adjust'])->name('api.v1.esm.service-contracts.adjust')->whereUuid('serviceContract');
        Route::post('/service-contracts/{serviceContract}/residual-report', [MaintenanceContractController::class, 'sendReport'])->name('api.v1.esm.service-contracts.residual-report')->whereUuid('serviceContract');
        Route::get('/equipment-passports/{passport}/history', [MaintenanceContractController::class, 'assetHistory'])->name('api.v1.esm.equipment-passports.history')->whereUuid('passport');

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
