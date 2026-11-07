<?php

use App\Models\PasswordResetToken;
use App\Models\Tenant;
use Enterprise\EventBus\Jobs\MaintainOutboxLifecycleJob;
use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\MultiTenant\Queue\TenantJobDispatcher;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Associations\Jobs\ExpireMembershipsJob;
use Modules\Associations\Jobs\SendCertificateAlertsJob;
use Modules\Associations\Jobs\SendFeeRemindersJob;
use Modules\Associations\Jobs\SendRenewalRemindersJob;
use Modules\Associations\Jobs\SendVolunteerAlertsJob;
use Modules\Customs\Jobs\RetrieveAssessmentDecisionsJob;
use Modules\HumanResources\Jobs\ProcessMonthlyLeaveAccrualsJob;
use Modules\HumanResources\Jobs\ScanExpiringCertificationsJob;
use Modules\Inventory\Jobs\EvaluateWarehouseReorderRulesJob;
use Modules\Inventory\Jobs\ExpireStaleReservationsJob;
use Modules\Inventory\Jobs\RecalculateInventoryValuationJob;
use Modules\Manufacturing\Jobs\ProcessManufacturingForecastJob;
use Modules\Manufacturing\Jobs\ProcessMrpRunJob;
use Modules\Manufacturing\Jobs\ProcessWorkCenterCapacityJob;
use Modules\Procurement\Jobs\CheckExpiringSupplierContractsJob;
use Modules\Procurement\Jobs\EvaluateSupplierOtifScoresJob;
use Modules\ServiceManagement\Jobs\ProcessServiceContractRenewalsJob;
use Modules\ServiceManagement\Jobs\ScanSLABreachesJob;
use Modules\SubscriptionBilling\Jobs\AggregateMeteredUsageJob;
use Modules\Workflow\Jobs\DispatchOutboundWebhooksJob;
use Modules\Workflow\Jobs\ProcessBusinessCalendarTimeoutsJob;
use Modules\Workflow\Jobs\PruneExpiredApprovalTokensJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tenants:backup --all')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('subscriptions:process')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('invoices:send-reminders')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('auth:prune-unverified-users')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [PasswordResetToken::class]])->dailyAt('04:15')->withoutOverlapping();
Schedule::command('ecommerce:sync-orders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sdi:poll')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('time:purge-expired')->dailyAt('03:30')->withoutOverlapping();

// Mantiene snella la tabella telescope_entries eliminando i dati oltre le 24 ore
Schedule::command('telescope:prune --hours=24')->dailyAt('03:00')->withoutOverlapping();

// Demo pubblica: database ricostruito da zero e storage svuotato ogni 30 minuti (solo con DEMO_RESET_ENABLED=true)
Schedule::command('demo:reset')->everyThirtyMinutes()->withoutOverlapping()->when(fn (): bool => (bool) config('app.demo_reset'));

// Outbox of the domain events and the jobs of the modules. Jobs of a company run once per tenant, in its context.
// Recurring billing, revenue recognition and depreciation stay manual: they write fiscal data and need the invoicing
// consolidation (subscriptions:process above still issues the real invoices).
Schedule::job(new RelayOutboxEventsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new MaintainOutboxLifecycleJob)->dailyAt('03:15')->withoutOverlapping();

$forEachTenant = static fn (string $name, Closure $job): CallbackEvent => Schedule::call(
    static fn (): int => app(TenantJobDispatcher::class)->dispatchForEachTenant($job)
)->name($name)->withoutOverlapping();

$forEachTenant('workflow:webhooks', static fn (): object => new DispatchOutboundWebhooksJob)->everyMinute();
$forEachTenant('workflow:timeouts', static fn (): object => new ProcessBusinessCalendarTimeoutsJob)->everyFifteenMinutes();
$forEachTenant('workflow:prune-tokens', static fn (): object => new PruneExpiredApprovalTokensJob)->dailyAt('03:20');
$forEachTenant('esm:sla-breaches', static fn (Tenant $tenant): object => new ScanSLABreachesJob($tenant->id))->everyFifteenMinutes();
$forEachTenant('esm:contract-renewals', static fn (Tenant $tenant): object => new ProcessServiceContractRenewalsJob($tenant->id))->dailyAt('05:00');
$forEachTenant('inventory:expire-reservations', static fn (): object => new ExpireStaleReservationsJob)->hourly();
$forEachTenant('inventory:reorder-rules', static fn (Tenant $tenant): object => new EvaluateWarehouseReorderRulesJob($tenant->id))->hourly();
$forEachTenant('inventory:valuation', static fn (Tenant $tenant): object => new RecalculateInventoryValuationJob($tenant->id))->dailyAt('01:30');
$forEachTenant('procurement:expiring-contracts', static fn (Tenant $tenant): object => new CheckExpiringSupplierContractsJob($tenant->id))->dailyAt('05:10');
$forEachTenant('procurement:supplier-otif', static fn (Tenant $tenant): object => new EvaluateSupplierOtifScoresJob($tenant->id))->monthlyOn(1, '05:20');
$forEachTenant('hr:expiring-certifications', static fn (Tenant $tenant): object => new ScanExpiringCertificationsJob($tenant->id))->dailyAt('05:30');
$forEachTenant('hr:leave-accruals', static fn (Tenant $tenant): object => new ProcessMonthlyLeaveAccrualsJob($tenant->id, now()->year))->monthlyOn(1, '05:40');
$forEachTenant('subscriptions:metered-usage', static fn (Tenant $tenant): object => new AggregateMeteredUsageJob($tenant->id))->dailyAt('01:45');
$forEachTenant('manufacturing:mrp', static fn (Tenant $tenant): object => new ProcessMrpRunJob($tenant->id, now()->toDateString(), now()->addDays(90)->toDateString()))->dailyAt('02:15');
$forEachTenant('manufacturing:capacity', static fn (Tenant $tenant): object => new ProcessWorkCenterCapacityJob($tenant->id, now()->toDateString()))->dailyAt('02:30');
$forEachTenant('manufacturing:forecast', static fn (Tenant $tenant): object => new ProcessManufacturingForecastJob($tenant->id))->monthlyOn(1, '02:45');
$forEachTenant('associations:expire-memberships', static fn (Tenant $tenant): object => new ExpireMembershipsJob($tenant->id))->dailyAt('00:30');
$forEachTenant('associations:fee-reminders', static fn (Tenant $tenant): object => new SendFeeRemindersJob($tenant->id))->dailyAt('08:15');
$forEachTenant('associations:renewal-reminders', static fn (Tenant $tenant): object => new SendRenewalRemindersJob($tenant->id))->dailyAt('08:30');
$forEachTenant('associations:certificate-alerts', static fn (Tenant $tenant): object => new SendCertificateAlertsJob($tenant->id))->dailyAt('08:45');
$forEachTenant('associations:volunteer-alerts', static fn (Tenant $tenant): object => new SendVolunteerAlertsJob($tenant->id))->dailyAt('07:45');
$forEachTenant('customs:assessment-decisions', static fn (Tenant $tenant): object => new RetrieveAssessmentDecisionsJob($tenant->id))->dailyAt('04:30');
