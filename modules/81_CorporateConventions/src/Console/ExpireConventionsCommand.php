<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\CorporateConventions\Services\ConventionService;

/**
 * Expires the conventions past their end date and the passes past their expiry, for every company.
 */
final class ExpireConventionsCommand extends Command
{
    protected $signature = 'corporate-conventions:expire';

    protected $description = 'Expire the corporate conventions and beneficiary passes past their end date';

    public function handle(ConventionService $conventions, TenantContext $tenantContext): int
    {
        $total = ['conventions' => 0, 'enrollments' => 0];

        foreach (Tenant::query()->cursor() as $tenant) {
            $result = $tenantContext->runWithTenant($tenant, fn (): array => $conventions->expireDue());
            $total['conventions'] += $result['conventions'];
            $total['enrollments'] += $result['enrollments'];
        }

        $this->info(sprintf('%d conventions and %d passes expired.', $total['conventions'], $total['enrollments']));

        return self::SUCCESS;
    }
}
