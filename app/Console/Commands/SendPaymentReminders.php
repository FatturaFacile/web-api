<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sales\SendPaymentReminder;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'invoices:send-reminders';

    protected $description = 'Queue escalating payment reminders for overdue invoices of every tenant';

    public function handle(TenantContext $tenantContext, SendPaymentReminder $reminder): int
    {
        $queued = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $reminder, &$queued): void {
            $tenantContext->runWithTenant($tenant, function () use ($reminder, &$queued): void {
                Invoice::query()
                    ->with('contact')
                    ->where('payment_status', '!=', 'paid')
                    ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
                    ->whereDate('due_date', '<', now())
                    ->get()
                    ->each(function (Invoice $invoice) use ($reminder, &$queued): void {
                        if ($reminder->execute($invoice) !== null) {
                            $queued++;
                        }
                    });
            });
        });

        $this->info("Reminders queued: {$queued}");

        return self::SUCCESS;
    }
}
