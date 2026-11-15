<?php

declare(strict_types=1);

namespace Modules\CRM\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\CRM\Services\AutomationService;
use Modules\CRM\Services\DealRegistrationService;

/**
 * Scheduled run of the CRM extension: executes the due marketing automation steps and expires the deal registrations whose
 * protection ended, tenant by tenant.
 */
final class ProcessCrmAutomationCommand extends Command
{
    protected $signature = 'crm:process-automation {--tenant= : Only this tenant id}';

    protected $description = 'Run the due automation steps and expire overdue deal registrations';

    public function handle(AutomationService $automation, DealRegistrationService $registrations, TenantContext $context): int
    {
        $only = $this->option('tenant');
        $steps = 0;
        $expired = 0;

        Tenant::query()
            ->when(is_string($only) && $only !== '', static fn ($query) => $query->whereKey($only))
            ->each(function (Tenant $tenant) use ($automation, $registrations, $context, &$steps, &$expired): void {
                $context->runWithTenant($tenant, function () use ($automation, $registrations, $tenant, &$steps, &$expired): void {
                    $steps += $automation->processDue((string) $tenant->id);
                    $expired += $registrations->expireOverdue((string) $tenant->id);
                });
            });

        $this->info(sprintf('Enrolments handled: %d, deal registrations expired: %d', $steps, $expired));

        return self::SUCCESS;
    }
}
