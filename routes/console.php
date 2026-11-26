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
use Modules\AiCognitive\Infrastructure\Jobs\NightlyPredictiveScoringJob;
use Modules\AssetManagement\Jobs\NotifyExpiringAssetCoverageJob;
use Modules\Associations\Jobs\ExpireMembershipsJob;
use Modules\Associations\Jobs\SendCertificateAlertsJob;
use Modules\Associations\Jobs\SendFeeRemindersJob;
use Modules\Associations\Jobs\SendRenewalRemindersJob;
use Modules\Associations\Jobs\SendVolunteerAlertsJob;
use Modules\Communication\Jobs\ProcessDueCampaignsJob;
use Modules\Contracts\Jobs\ExpireSignatureRequestsJob;
use Modules\Contracts\Jobs\ProcessContractDeadlinesJob;
use Modules\CRM\Jobs\SendCrmActivityRemindersJob;
use Modules\Customs\Jobs\RetrieveAssessmentDecisionsJob;
use Modules\Documents\Jobs\AnchorPreservationChainJob;
use Modules\Documents\Jobs\ApplyDocumentRetentionJob;
use Modules\DocumentTrust\Jobs\VerifyPreservationPackagesJob;
use Modules\ElectronicSignature\Jobs\ProcessSignatureLifecycleJob;
use Modules\Facility\Jobs\GenerateFacilityInspectionsJob;
use Modules\Facility\Jobs\ReleaseNoShowBookingsJob;
use Modules\FiscalRetail\Jobs\RetryCorrispettiviTransmissionsJob;
use Modules\FranchiseNetwork\Jobs\CalculateRoyaltiesJob;
use Modules\FranchiseNetwork\Jobs\MaintainFranchiseNetworkJob;
use Modules\FranchiseNetwork\Jobs\SnapshotBenchmarksJob;
use Modules\Hospitality\Jobs\ReleaseExpiredGroupBlocksJob;
use Modules\HumanResources\Jobs\ProcessMonthlyLeaveAccrualsJob;
use Modules\HumanResources\Jobs\PurgeExpiredApplicationsJob;
use Modules\HumanResources\Jobs\PurgeExpiredEmployeeDocumentsJob;
use Modules\HumanResources\Jobs\ScanExpiringCertificationsJob;
use Modules\Inventory\Jobs\EvaluateWarehouseReorderRulesJob;
use Modules\Inventory\Jobs\ExpireStaleReservationsJob;
use Modules\Inventory\Jobs\ExpireStockLotsJob;
use Modules\Inventory\Jobs\RecalculateInventoryValuationJob;
use Modules\Manufacturing\Jobs\MaterialiseShiftInstancesJob;
use Modules\Manufacturing\Jobs\ProcessManufacturingForecastJob;
use Modules\Manufacturing\Jobs\ProcessMrpRunJob;
use Modules\MarketingAutomation\Jobs\EvaluateRfmSegmentsJob;
use Modules\MarketingAutomation\Jobs\RunMarketingJourneysJob;
use Modules\Platform\Jobs\EvaluateNotificationAlertRulesJob;
use Modules\PracticeManagement\Jobs\ExpireFeeProposalsJob;
use Modules\Procurement\Jobs\CheckExpiringSupplierContractsJob;
use Modules\Procurement\Jobs\EvaluateSupplierOtifScoresJob;
use Modules\Procurement\Jobs\RunInvoiceMatchingJob;
use Modules\Procurement\Jobs\SendOverdueOrderRemindersJob;
use Modules\Projects\Jobs\ComputeProjectEvmSnapshotsJob;
use Modules\ServiceManagement\Jobs\GeneratePreventiveMaintenanceWorkOrdersJob;
use Modules\ServiceManagement\Jobs\PollSupportMailboxesJob;
use Modules\ServiceManagement\Jobs\ProcessServiceContractRenewalsJob;
use Modules\ServiceManagement\Jobs\ScanSLABreachesJob;
use Modules\SubscriptionBilling\Jobs\AggregateMeteredUsageJob;
use Modules\SubscriptionBilling\Jobs\ProcessRecurringBillingJob;
use Modules\SubscriptionBilling\Jobs\ProcessSubscriptionIndexationJob;
use Modules\SubscriptionBilling\Jobs\ProcessSubscriptionRenewalsJob;
use Modules\SubscriptionBilling\Jobs\RecognizeRevenueScheduleJob;
use Modules\Treasury\Currency\Infrastructure\Jobs\ReverseRevaluationsJob;
use Modules\Treasury\Currency\Infrastructure\Jobs\SyncDailyRatesJob;
use Modules\Treasury\Currency\Infrastructure\Jobs\SyncEstvMonthlyRatesJob;
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
// Backups: daily (default) or hourly, from config/backup.php. A weekly restore drill proves the archives restore, and the
// hourly check alerts when a tenant's backup is stale or failing against the RPO.
$backupSchedule = Schedule::command('tenants:backup --all')->withoutOverlapping()->onOneServer();
config('backup.schedule.frequency') === 'hourly' ? $backupSchedule->hourly() : $backupSchedule->dailyAt((string) config('backup.schedule.daily_at', '02:00'));
if (config('backup.schedule.drill_enabled', true)) {
    Schedule::command('tenants:restore-drill')->weeklyOn(0, '04:00')->withoutOverlapping()->onOneServer();
}
if (config('backup.schedule.check_hourly', true)) {
    Schedule::command('tenants:backup-check')->hourly()->withoutOverlapping()->onOneServer();
}
Schedule::command('subscriptions:process')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
// Movements of the open banking consents (PSD2 / SIX bLink) imported as bank statements (idempotent).
Schedule::command('treasury:open-banking-sync')->dailyAt('06:15')->withoutOverlapping()->onOneServer();

// Multi-currency engine (Mod 003): the ECB and SNB rates after the ECB publication (around 16:00 CET), the ESTV monthly averages for
// Swiss VAT, and the reversal of the posted FX revaluations on the first day of the next period.
Schedule::job(new SyncDailyRatesJob)->dailyAt((string) config('currency_fx.daily_sync_at', '16:30'))->timezone('Europe/Zurich')->withoutOverlapping()->onOneServer();
Schedule::job(new SyncEstvMonthlyRatesJob)->monthlyOn(3, '07:00')->timezone('Europe/Zurich')->withoutOverlapping()->onOneServer();
Schedule::job(new ReverseRevaluationsJob)->dailyAt('00:20')->withoutOverlapping()->onOneServer();

// Recurring journals and automatic reversals (accruals, deferrals, FX revaluations) due, per tenant business day.
Schedule::command('general-ledger:run-scheduled')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
// AI forecasting (Mod 094): payment models first, because the liquidity projection reads the expected collection dates.
Schedule::command('forecasting:train-payment-models')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('forecasting:refresh-liquidity')->dailyAt('03:20')->withoutOverlapping()->onOneServer();
Schedule::command('liquidity:evaluate-alerts')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command('forecasting:generate-sku-demand')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
// Analytics: daily KPI snapshots (the day that just ended) first, then the scheduled report exports.
Schedule::command('analytics:refresh-kpi-snapshots')->dailyAt('01:10')->withoutOverlapping()->onOneServer();
Schedule::command('analytics:run-scheduled-exports')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('analytics:dispatch-scheduled-reports')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('exports:run-scheduled')->dailyAt('05:30')->withoutOverlapping()->onOneServer();
// Business intelligence: the scheduled snapshots of saved queries, after the KPI snapshots of the day that just ended.
Schedule::command('business-intelligence:run-snapshots')->dailyAt('01:40')->withoutOverlapping()->onOneServer();
// Marketplace: health of the enabled connector installations.
Schedule::command('marketplace:check-health')->everySixHours()->withoutOverlapping()->onOneServer();
Schedule::command('invoices:send-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('sales:expire-quotes')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('supplier-portal:alert-expiring-documents')->dailyAt('05:50')->withoutOverlapping()->onOneServer();
Schedule::command('auth:prune-unverified-users')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
// Expired Bearer tokens stop working at once; this only removes their rows.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->dailyAt('04:05')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [PasswordResetToken::class]])->dailyAt('04:15')->withoutOverlapping()->onOneServer();
Schedule::command('ecommerce:sync-orders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('sdi:poll')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('sdi:expire-pa-deadlines')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('time:purge-expired')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
// Anonymises personal data past the retention policies of each company (privacy:apply-retention --dry-run only counts).
Schedule::command('privacy:apply-retention')->dailyAt('03:55')->withoutOverlapping()->onOneServer();
// Privacy Governance retention rules (module 096): expired candidates, leads and tickets are anonymised; fiscal records never.
Schedule::command('privacy:run-retention-purge')->dailyAt('04:10')->withoutOverlapping()->onOneServer();

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
Schedule::command('crm:process-automation')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('budgeting:reconcile-commitments')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

$forEachTenant = static fn (string $name, Closure $job): CallbackEvent => Schedule::call(
    static fn (): int => app(TenantJobDispatcher::class)->dispatchForEachTenant($job)
)->name($name)->withoutOverlapping()->onOneServer();

// AI cognitive layer (Mod 093): predictive recommendations and the anomaly scan after the forecasting models (03:00-03:40).
$forEachTenant('ai-cognitive:nightly-scoring', static fn (Tenant $tenant): object => new NightlyPredictiveScoringJob($tenant->id))->dailyAt('04:10');
$forEachTenant('workflow:webhooks', static fn (): object => new DispatchOutboundWebhooksJob)->everyMinute();
$forEachTenant('workflow:timeouts', static fn (): object => new ProcessBusinessCalendarTimeoutsJob)->everyFifteenMinutes();
$forEachTenant('workflow:prune-tokens', static fn (): object => new PruneExpiredApprovalTokensJob)->dailyAt('03:20');
$forEachTenant('crm:activity-reminders', static fn (Tenant $tenant): object => new SendCrmActivityRemindersJob($tenant->id))->everyFiveMinutes();
// Projects (Mod 082): nightly earned value snapshot of the running projects; raises the CPI, SPI and budget alerts.
$forEachTenant('projects:evm-snapshots', static fn (): object => new ComputeProjectEvmSnapshotsJob)->dailyAt('03:50');
$forEachTenant('esm:sla-breaches', static fn (Tenant $tenant): object => new ScanSLABreachesJob($tenant->id))->everyFiveMinutes();
$forEachTenant('esm:support-mailboxes', static fn (Tenant $tenant): object => new PollSupportMailboxesJob($tenant->id))->everyTwoMinutes();
$forEachTenant('esm:preventive-maintenance', static fn (Tenant $tenant): object => new GeneratePreventiveMaintenanceWorkOrdersJob($tenant->id))->dailyAt('04:45');
$forEachTenant('esm:contract-renewals', static fn (Tenant $tenant): object => new ProcessServiceContractRenewalsJob($tenant->id))->dailyAt('05:00');
$forEachTenant('inventory:expire-reservations', static fn (): object => new ExpireStaleReservationsJob)->hourly();
$forEachTenant('inventory:reorder-rules', static fn (Tenant $tenant): object => new EvaluateWarehouseReorderRulesJob($tenant->id))->hourly();
$forEachTenant('inventory:valuation', static fn (Tenant $tenant): object => new RecalculateInventoryValuationJob($tenant->id))->dailyAt('01:30');
$forEachTenant('procurement:expiring-contracts', static fn (Tenant $tenant): object => new CheckExpiringSupplierContractsJob($tenant->id))->dailyAt('05:10');
$forEachTenant('procurement:supplier-otif', static fn (Tenant $tenant): object => new EvaluateSupplierOtifScoresJob($tenant->id))->monthlyOn(1, '05:20');
$forEachTenant('procurement:overdue-order-reminders', static fn (Tenant $tenant): object => new SendOverdueOrderRemindersJob($tenant->id))->dailyAt('07:30');
$forEachTenant('procurement:invoice-matching', static fn (Tenant $tenant): object => new RunInvoiceMatchingJob($tenant->id))->hourly();
$forEachTenant('hr:expiring-certifications', static fn (Tenant $tenant): object => new ScanExpiringCertificationsJob($tenant->id))->dailyAt('05:30');
$forEachTenant('eam:expiring-coverage', static fn (Tenant $tenant): object => new NotifyExpiringAssetCoverageJob($tenant->id))->dailyAt('05:35');
$forEachTenant('platform:notification-alert-rules', static fn (Tenant $tenant): object => new EvaluateNotificationAlertRulesJob($tenant->id))->everyFifteenMinutes();
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
// Anchoring is idempotent: it appends only the links that are missing.
$forEachTenant('dms:anchor-preservation-chain', static fn (Tenant $tenant): object => new AnchorPreservationChainJob($tenant->id))->dailyAt('03:30');
$forEachTenant('document-trust:verify-preservation', static fn (Tenant $tenant): object => new VerifyPreservationPackagesJob($tenant->id))->weeklyOn(0, '02:30');
// Before procurement:expiring-contracts at 05:10, so a supplier contract that renews by itself is not marked expired.
$forEachTenant('contracts:deadlines', static fn (Tenant $tenant): object => new ProcessContractDeadlinesJob($tenant->id))->dailyAt('04:50');
$forEachTenant('contracts:expire-signature-requests', static fn (Tenant $tenant): object => new ExpireSignatureRequestsJob($tenant->id))->dailyAt('04:55');
// Electronic signatures: reminders before the expiry, expiry of overdue envelopes, pending provider signatures.
$forEachTenant('signatures:lifecycle', static fn (): object => new ProcessSignatureLifecycleJob)->everyFifteenMinutes();
$forEachTenant('workspace:sync', static fn (Tenant $tenant): object => new SyncWorkspaceConnectionsJob($tenant->id))->hourly();
$forEachTenant('visitors:purge', static fn (Tenant $tenant): object => new PurgeExpiredVisitorsJob($tenant->id))->dailyAt('03:50');
$forEachTenant('facility:release-no-shows', static fn (Tenant $tenant): object => new ReleaseNoShowBookingsJob($tenant->id))->everyFiveMinutes();
$forEachTenant('hospitality:release-expired-blocks', static fn (Tenant $tenant): object => new ReleaseExpiredGroupBlocksJob($tenant->id))->dailyAt('00:10');
$forEachTenant('practice:expire-fee-proposals', static fn (Tenant $tenant): object => new ExpireFeeProposalsJob($tenant->id))->dailyAt('00:20');
$forEachTenant('facility:generate-inspections', static fn (Tenant $tenant): object => new GenerateFacilityInspectionsJob($tenant->id))->dailyAt('05:45');
$forEachTenant('fiscal-retail:retry-transmissions', static fn (Tenant $tenant): object => new RetryCorrispettiviTransmissionsJob($tenant->id))->everyFiveMinutes();
$forEachTenant('communication:due-campaigns', static fn (Tenant $tenant): object => new ProcessDueCampaignsJob($tenant->id))->everyMinute();

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
// Inflation indexation (ISTAT FOI / BFS LIK) first: the cycle billed at 02:00-02:05 already carries the indexed fee.
$completenessTenantJob('subscriptions:indexation', static fn (Tenant $tenant): object => new ProcessSubscriptionIndexationJob($tenant->id))->dailyAt('01:55');
$completenessTenantJob('subscriptions:billing-run', static fn (Tenant $tenant): object => new ProcessRecurringBillingJob($tenant->id))
    ->dailyAt('02:05')->when(static fn (): bool => (bool) config('subscription_billing.scheduled_billing'));
$completenessTenantJob('subscriptions:revenue-recognition', static fn (Tenant $tenant): object => new RecognizeRevenueScheduleJob($tenant->id))
    ->monthlyOn(1, '02:20')->when(static fn (): bool => (bool) config('subscription_billing.scheduled_billing'));
$completenessTenantJob('inventory:expire-lots', static fn (Tenant $tenant): object => new ExpireStockLotsJob($tenant->id))->dailyAt('00:45');
// Mod 155 Marketing Automation: hourly RFM audience refresh and the five-minute journey step runner.
$completenessTenantJob('marketing:refresh-segments', static fn (Tenant $tenant): object => new EvaluateRfmSegmentsJob($tenant->id))->hourly();
$completenessTenantJob('marketing:run-journeys', static fn (Tenant $tenant): object => new RunMarketingJourneysJob($tenant->id))->everyFiveMinutes();
$completenessTenantJob('appointments:reminders', static fn (Tenant $tenant): object => new QueueAppointmentRemindersJob($tenant->id))->everyFifteenMinutes();

// Module 17 ESM (GH-396): alert once per loaner unit still out after the repaired equipment was returned
Schedule::command('esm:loaners:scan-overdue')->dailyAt('07:30')->withoutOverlapping()->onOneServer();

// GitHub Issue #138: Recurring Contracts & Periodic Billing Engine
Schedule::command('billing:process-recurring')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('audit:archive-worm')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Digital preservation (AgID conservazione / GeBüV): collect, seal, time-stamp and dispatch; then re-verify the vault.
Schedule::command('fatturafacile:preservation:run')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('fatturafacile:preservation:verify')->weeklyOn(0, '04:00')->withoutOverlapping()->onOneServer();

// Workplace health and safety (Mod 099): the 60/30/7 day and overdue alerts for certificates, medical visits and PPE.
Schedule::command('hse:check-expirations')->dailyAt('06:30')->withoutOverlapping()->onOneServer();

// Tender management (Mod 169): hourly deadline milestones (7 days, 72 h, 24 h, 4 h) and qualification vault expirations.
Schedule::command('tender:scan-alerts')->hourly()->withoutOverlapping()->onOneServer();

// MES Core integration (issue #184): the UTC shift instances keep a moving horizon, and the change log is compacted.
$completenessTenantJob('mfg:mes-shift-instances', static fn (Tenant $tenant): object => new MaterialiseShiftInstancesJob($tenant->id))->dailyAt('00:30');
Schedule::command('mfg:mes-prune-changes')->dailyAt('03:45')->withoutOverlapping()->onOneServer();

// GDO trade terms (Mod 231): monthly accrual of the rebates and trade contributions of the month that just ended.
Schedule::command('gdo-trade-terms:accrue')->monthlyOn(1, '04:30')->withoutOverlapping()->onOneServer();

// Franchise network governance (Mod 244): daily upkeep (renewals, reminders, entry fee deferral, push retries), the royalty and marketing
// fund run of the month that just ended on the 2nd, and the anonymous benchmark snapshot once the sell-out of the month is in.
$completenessTenantJob('franchise-network:maintenance', static fn (Tenant $tenant): object => new MaintainFranchiseNetworkJob($tenant->id))->dailyAt('03:10');
$completenessTenantJob('franchise-network:royalties', static fn (Tenant $tenant): object => new CalculateRoyaltiesJob($tenant->id))->monthlyOn(2, '04:00');
$completenessTenantJob('franchise-network:benchmark', static fn (Tenant $tenant): object => new SnapshotBenchmarksJob($tenant->id))->monthlyOn(3, '04:30');

// Passenger transport (Mod 176): follows the flights of the airport transfers and moves the pickup to the real arrival.
Schedule::command('passenger-transport:track-flights')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Customer returns & advance replacements (Mod 232): scan overdue cross-shipping returns and issue penalty invoices.
Schedule::command('returns:scan-overdue-replacements')->dailyAt('07:45')->withoutOverlapping()->onOneServer();

// Customer satisfaction (Mod 245): sends the due survey invitations and schedules the semi-annual relationship survey of the active clients.
Schedule::command('surveys:process-dispatches')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('surveys:enqueue-semi-annual')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
// Corporate conventions (Mod 358): expires the conventions and beneficiary passes past their end date.
Schedule::command('corporate-conventions:expire')->dailyAt('01:15')->withoutOverlapping()->onOneServer();
