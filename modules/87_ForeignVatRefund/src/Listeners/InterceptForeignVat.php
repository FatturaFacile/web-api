<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Listeners;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Modules\ExpenseManagement\Events\ExpenseReportApprovedEvent;
use Modules\ExpenseManagement\Events\ExpenseReportPostedEvent;
use Modules\ForeignVatRefund\Services\ForeignVatInterceptor;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;
use Throwable;

/**
 * Hooks of the foreign VAT interception: a posted supplier invoice, an approved expense report (lines recorded, waiting for the payout) and
 * a posted expense report (journal entries booked). A failure here never blocks the registration of the document: it is logged and the
 * ledger line, if it was created, carries the posting error for the accountant.
 */
final class InterceptForeignVat
{
    public function __construct(private readonly ForeignVatInterceptor $interceptor, private readonly TenantContext $context) {}

    public function onPassiveInvoicePosted(PassiveInvoicePostedEvent $event): void
    {
        $this->guarded($event->tenantId, function () use ($event): void {
            $invoice = PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->invoiceId)->first();

            if ($invoice !== null) {
                $this->interceptor->fromPassiveInvoice($invoice);
            }
        });
    }

    public function onExpenseReportApproved(ExpenseReportApprovedEvent $event): void
    {
        $this->guarded($event->tenantId, fn () => $this->interceptor->fromExpenseReport($event->reportId, $event->tenantId, false));
    }

    public function onExpenseReportPosted(ExpenseReportPostedEvent $event): void
    {
        $this->guarded($event->tenantId, fn () => $this->interceptor->fromExpenseReport($event->reportId, $event->tenantId, true));
    }

    private function guarded(string $tenantId, callable $work): void
    {
        try {
            $tenant = Tenant::query()->whereKey($tenantId)->first();

            if ($tenant !== null) {
                $this->context->runWithTenant($tenant, $work);
            }
        } catch (Throwable $exception) {
            Log::warning('Foreign VAT interception failed', ['tenant_id' => $tenantId, 'error' => $exception->getMessage()]);
        }
    }
}
