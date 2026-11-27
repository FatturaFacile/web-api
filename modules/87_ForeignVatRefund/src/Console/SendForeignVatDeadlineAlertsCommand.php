<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Services\DeadlineAlertService;

/**
 * Daily run of the foreign VAT refund alerts (60/30/15 days before 30 June and 30 September, expiring mandates and certificates). Safe to repeat.
 */
final class SendForeignVatDeadlineAlertsCommand extends Command
{
    protected $signature = 'foreign-vat:deadline-alerts {--tenant=* : Limit to these tenants}';

    protected $description = 'Sends the priority alerts for the peremptory foreign VAT refund deadlines and the expiring fiscal representative mandates';

    public function handle(TenantContext $tenantContext, DeadlineAlertService $alerts): int
    {
        $ids = ForeignVatLedgerEntry::withoutGlobalScopes()->whereNull('deleted_at')->distinct()->pluck('tenant_id')
            ->merge(FiscalRepresentativeMandate::withoutGlobalScopes()->whereNull('deleted_at')->distinct()->pluck('tenant_id'))
            ->filter()->unique()->all();

        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->whereIn('id', $ids)
            ->when($this->option('tenant'), fn ($query, $only) => $query->whereIn('id', $only))
            ->get();

        foreach ($tenants as $tenant) {
            $sent = $tenantContext->runWithTenant($tenant, fn (): int => $alerts->run($tenant));
            $this->line("{$tenant->id}: {$sent} alerts sent");
        }

        return self::SUCCESS;
    }
}
