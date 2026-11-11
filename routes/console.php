<?php

use App\Jobs\Appointments\QueueAppointmentRemindersJob;
use App\Models\PasswordResetToken;
use App\Models\Tenant;
use Enterprise\EventBus\Jobs\MaintainOutboxLifecycleJob;
use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\MultiTenant\Queue\TenantJobDispatcher;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Modules\AssetManagement\Jobs\NotifyExpiringAssetCoverageJob;
use Modules\Associations\Jobs\ExpireMembershipsJob;
use Modules\Associations\Jobs\SendCertificateAlertsJob;
use Modules\Associations\Jobs\SendFeeRemindersJob;
use Modules\Associations\Jobs\SendRenewalRemindersJob;
use Modules\Associations\Jobs\SendVolunteerAlertsJob;
use Modules\Contracts\Jobs\ProcessContractDeadlinesJob;
use Modules\CRM\Jobs\SendCrmActivityRemindersJob;
use Modules\Customs\Jobs\RetrieveAssessmentDecisionsJob;
use Modules\Documents\Jobs\ApplyDocumentRetentionJob;
use Modules\DocumentTrust\Jobs\VerifyPreservationPackagesJob;
use Modules\FiscalRetail\Jobs\RetryCorrispettiviTransmissionsJob;
use Modules\HumanResources\Jobs\ProcessMonthlyLeaveAccrualsJob;
use Modules\HumanResources\Jobs\PurgeExpiredApplicationsJob;
use Modules\HumanResources\Jobs\PurgeExpiredEmployeeDocumentsJob;
use Modules\HumanResources\Jobs\ScanExpiringCertificationsJob;
use Modules\Inventory\Jobs\EvaluateWarehouseReorderRulesJob;
use Modules\Inventory\Jobs\ExpireStaleReservationsJob;
use Modules\Inventory\Jobs\ExpireStockLotsJob;
use Modules\Inventory\Jobs\RecalculateInventoryValuationJob;
use Modules\Manufacturing\Jobs\ProcessManufacturingForecastJob;
use Modules\Manufacturing\Jobs\ProcessMrpRunJob;
use Modules\Procurement\Jobs\CheckExpiringSupplierContractsJob;
use Modules\Procurement\Jobs\EvaluateSupplierOtifScoresJob;
use Modules\ServiceManagement\Jobs\PollSupportMailboxesJob;
use Modules\ServiceManagement\Jobs\ProcessServiceContractRenewalsJob;
use Modules\ServiceManagement\Jobs\ScanSLABreachesJob;
use Modules\SubscriptionBilling\Jobs\AggregateMeteredUsageJob;
use Modules\SubscriptionBilling\Jobs\ProcessRecurringBillingJob;
use Modules\SubscriptionBilling\Jobs\ProcessSubscriptionRenewalsJob;
use Modules\SubscriptionBilling\Jobs\RecognizeRevenueScheduleJob;
use Modules\Visitors\Jobs\PurgeExpiredVisitorsJob;
use Modules\Workflow\Jobs\DispatchOutboundWebhooksJob;
use Modules\Workflow\Jobs\ProcessBusinessCalendarTimeoutsJob;
use Modules\Workflow\Jobs\PruneExpiredApprovalTokensJob;
use Modules\WorkspaceSync\Jobs\SyncWorkspaceConnectionsJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every entry runs on one server only and never overlaps itself: both use atomic locks of the default cache store
// (database or redis, shared by every server; see docs/deployment/PRODUCTION_CHECKLIST.md).
Schedule::command('tenants:backup --all')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('subscriptions:process')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
// Recurring journals and automatic reversals (accruals, deferrals, FX revaluations) due, per tenant business day.
Schedule::command('general-ledger:run-scheduled')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('invoices:send-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('sales:expire-quotes')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('auth:prune-unverified-users')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
// Expired Bearer tokens stop working at once; this only removes their rows.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->dailyAt('04:05')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [PasswordResetToken::class]])->dailyAt('04:15')->withoutOverlapping()->onOneServer();
Schedule::command('ecommerce:sync-orders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('sdi:poll')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('time:purge-expired')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
// Anonymises personal data past the retention policies of each company (privacy:apply-retention --dry-run only counts).
Schedule::command('privacy:apply-retention')->dailyAt('03:55')->withoutOverlapping()->onOneServer();

// Mantiene snella la tabella telescope_entries eliminando i dati oltre le 24 ore. Telescope e' una dipendenza di sviluppo
// spenta in produzione (TELESCOPE_ENABLED=false) e le sue tabelle non sono migrate: il prune gira solo se e' attivo
// e la tabella esiste, altrimenti fallirebbe ogni notte.
Schedule::command('telescope:prune --hours=24')->dailyAt('03:00')->withoutOverlapping()->onOneServer()
    ->when(static fn (): bool => (bool) config('telescope.enabled')
        && Schema::connection(config('telescope.storage.database.connection'))->hasTable('telescope_entries'));

// Demo pubblica: database ricostruito da zero e storage svuotato ogni 30 minuti (solo con DEMO_RESET_ENABLED=true)
Schedule::command('demo:reset')->everyThirtyMinutes()->withoutOverlapping()->onOneServer()->when(fn (): bool => (bool) config('app.demo_reset'));

// Outbox of the domain events and the jobs of the modules. Jobs of a company run once per tenant, in its context.
// Depreciation stays manual: it posts fiscal entries closed by the accountant. Subscription billing and revenue
// recognition run on the subscription engine (real invoices, idempotent per period): see config/subscription_billing.php.
Schedule::job(new RelayOutboxEventsJob)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::job(new MaintainOutboxLifecycleJob)->dailyAt('03:15')->withoutOverlapping()->onOneServer();

$forEachTenant = static fn (string $name, Closure $job): CallbackEvent => Schedule::call(
    static fn (): int => app(TenantJobDispatcher::class)->dispatchForEachTenant($job)
)->name($name)->withoutOverlapping()->onOneServer();

$forEachTenant('workflow:webhooks', static fn (): object => new DispatchOutboundWebhooksJob)->everyMinute();
$forEachTenant('workflow:timeouts', static fn (): object => new ProcessBusinessCalendarTimeoutsJob)->everyFifteenMinutes();
$forEachTenant('workflow:prune-tokens', static fn (): object => new PruneExpiredApprovalTokensJob)->dailyAt('03:20');
$forEachTenant('crm:activity-reminders', static fn (Tenant $tenant): object => new SendCrmActivityRemindersJob($tenant->id))->everyFiveMinutes();
$forEachTenant('esm:sla-breaches', static fn (Tenant $tenant): object => new ScanSLABreachesJob($tenant->id))->everyFiveMinutes();
$forEachTenant('esm:support-mailboxes', static fn (Tenant $tenant): object => new PollSupportMailboxesJob($tenant->id))->everyTwoMinutes();
$forEachTenant('esm:contract-renewals', static fn (Tenant $tenant): object => new ProcessServiceContractRenewalsJob($tenant->id))->dailyAt('05:00');
$forEachTenant('inventory:expire-reservations', static fn (): object => new ExpireStaleReservationsJob)->hourly();
$forEachTenant('inventory:reorder-rules', static fn (Tenant $tenant): object => new EvaluateWarehouseReorderRulesJob($tenant->id))->hourly();
$forEachTenant('inventory:valuation', static fn (Tenant $tenant): object => new RecalculateInventoryValuationJob($tenant->id))->dailyAt('01:30');
$forEachTenant('procurement:expiring-contracts', static fn (Tenant $tenant): object => new CheckExpiringSupplierContractsJob($tenant->id))->dailyAt('05:10');
$forEachTenant('procurement:supplier-otif', static fn (Tenant $tenant): object => new EvaluateSupplierOtifScoresJob($tenant->id))->monthlyOn(1, '05:20');
$forEachTenant('hr:expiring-certifications', static fn (Tenant $tenant): object => new ScanExpiringCertificationsJob($tenant->id))->dailyAt('05:30');
$forEachTenant('eam:expiring-coverage', static fn (Tenant $tenant): object => new NotifyExpiringAssetCoverageJob($tenant->id))->dailyAt('05:35');
$forEachTenant('hr:leave-accruals', static fn (Tenant $tenant): object => new ProcessMonthlyLeaveAccrualsJob($tenant->id, now()->year))->monthlyOn(1, '05:40');
$forEachTenant('hr:purge-applications', static fn (Tenant $tenant): object => new PurgeExpiredApplicationsJob($tenant->id))->dailyAt('03:40');
$forEachTenant('hr:purge-employee-documents', static fn (Tenant $tenant): object => new PurgeExpiredEmployeeDocumentsJob($tenant->id))->dailyAt('03:45');
$forEachTenant('subscriptions:metered-usage', static fn (Tenant $tenant): object => new AggregateMeteredUsageJob($tenant->id))->dailyAt('01:45');
$forEachTenant('manufacturing:mrp', static fn (Tenant $tenant): object => new ProcessMrpRunJob($tenant->id, now()->toDateString(), now()->addDays(90)->toDateString()))->dailyAt('02:15');
$forEachTenant('manufacturing:forecast', static fn (Tenant $tenant): object => new ProcessManufacturingForecastJob($tenant->id))->monthlyOn(1, '02:45');
$forEachTenant('associations:expire-memberships', static fn (Tenant $tenant): object => new ExpireMembershipsJob($tenant->id))->dailyAt('00:30');
$forEachTenant('associations:fee-reminders', static fn (Tenant $tenant): object => new SendFeeRemindersJob($tenant->id))->dailyAt('08:15');
$forEachTenant('associations:renewal-reminders', static fn (Tenant $tenant): object => new SendRenewalRemindersJob($tenant->id))->dailyAt('08:30');
$forEachTenant('associations:certificate-alerts', static fn (Tenant $tenant): object => new SendCertificateAlertsJob($tenant->id))->dailyAt('08:45');
$forEachTenant('associations:volunteer-alerts', static fn (Tenant $tenant): object => new SendVolunteerAlertsJob($tenant->id))->dailyAt('07:45');
$forEachTenant('customs:assessment-decisions', static fn (Tenant $tenant): object => new RetrieveAssessmentDecisionsJob($tenant->id))->dailyAt('04:30');
$forEachTenant('dms:retention', static fn (Tenant $tenant): object => new ApplyDocumentRetentionJob($tenant->id))->dailyAt('03:40');
$forEachTenant('document-trust:verify-preservation', static fn (Tenant $tenant): object => new VerifyPreservationPackagesJob($tenant->id))->weeklyOn(0, '02:30');
// Before procurement:expiring-contracts at 05:10, so a supplier contract that renews by itself is not marked expired.
$forEachTenant('contracts:deadlines', static fn (Tenant $tenant): object => new ProcessContractDeadlinesJob($tenant->id))->dailyAt('04:50');
$forEachTenant('workspace:sync', static fn (Tenant $tenant): object => new SyncWorkspaceConnectionsJob($tenant->id))->hourly();
$forEachTenant('visitors:purge', static fn (Tenant $tenant): object => new PurgeExpiredVisitorsJob($tenant->id))->dailyAt('03:50');
$forEachTenant('fiscal-retail:retry-transmissions', static fn (Tenant $tenant): object => new RetryCorrispettiviTransmissionsJob($tenant->id))->everyFiveMinutes();

/*
|--------------------------------------------------------------------------
| Completeness fixes: tenant jobs that had no schedule
|--------------------------------------------------------------------------
|
| Kept apart from the entries above. Every entry runs once per active tenant, in its context, on one server.
| - subscriptions:renewals extends the module subscriptions that renew by themselves (no fiscal data).
| - subscriptions:billing-run (a retry pass after subscriptions:process) and subscriptions:revenue-recognition run
|   unless SUBSCRIPTION_BILLING_SCHEDULED=false (config/subscription_billing.php): the engine issues real invoices
|   through the invoicing services and bills each period once, however many runs there are.
| - inventory:expire-lots quarantines the lots past their expiration date (LotExpiredEvent).
| - appointments:reminders queues the reminders of the appointments within appointments.reminder_lead_minutes.
| Depreciation (ExecuteMonthly/Quarterly/YearlyDepreciationJob) stays manual: it posts fiscal entries and is closed by
| the accountant. ExecuteAutomatedThreeWayMatchJob is per document (purchase order, receipt, supplier invoice), not
| periodic: it is dispatched when the documents to match exist.
*/
$completenessTenantJob = static fn (string $name, Closure $job): CallbackEvent => Schedule::call(
    static fn (): int => app(TenantJobDispatcher::class)->dispatchForEachTenant($job)
)->name($name)->withoutOverlapping()->onOneServer();

$completenessTenantJob('subscriptions:renewals', static fn (Tenant $tenant): object => new ProcessSubscriptionRenewalsJob($tenant->id))->dailyAt('01:50');
$completenessTenantJob('subscriptions:billing-run', static fn (Tenant $tenant): object => new ProcessRecurringBillingJob($tenant->id))
    ->dailyAt('02:05')->when(static fn (): bool => (bool) config('subscription_billing.scheduled_billing'));
$completenessTenantJob('subscriptions:revenue-recognition', static fn (Tenant $tenant): object => new RecognizeRevenueScheduleJob($tenant->id))
    ->monthlyOn(1, '02:20')->when(static fn (): bool => (bool) config('subscription_billing.scheduled_billing'));
$completenessTenantJob('inventory:expire-lots', static fn (Tenant $tenant): object => new ExpireStockLotsJob($tenant->id))->dailyAt('00:45');
$completenessTenantJob('appointments:reminders', static fn (Tenant $tenant): object => new QueueAppointmentRemindersJob($tenant->id))->everyFifteenMinutes();
