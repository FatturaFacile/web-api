<?php

declare(strict_types=1);

namespace Modules\Procurement\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Procurement\Services\InvoiceMatchingService;

/**
 * Automatic matching run of a company: the supplier invoices that quote or fit a purchase order and were not matched
 * yet are matched against the order and the goods received, and released or blocked for discrepancy.
 */
final class RunInvoiceMatchingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $limit = 200,
    ) {
        $this->afterCommit();
    }

    public function handle(InvoiceMatchingService $matching, TenantContext $context): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runWithTenant($tenant, fn (): array => $matching->runBatch(null, $this->limit));
    }
}
