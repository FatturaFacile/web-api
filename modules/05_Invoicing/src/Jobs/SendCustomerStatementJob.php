<?php

declare(strict_types=1);

namespace Modules\Invoicing\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Modules\Invoicing\Mail\CustomerStatementMail;
use Modules\Invoicing\Models\CustomerStatementDispatch;
use Modules\Invoicing\Services\Receivables\CustomerStatementExporter;
use Modules\Invoicing\Services\Receivables\CustomerStatementService;
use Throwable;

/**
 * Builds a customer statement as PDF and Excel and emails it to the recipient recorded in the dispatch log.
 */
final class SendCustomerStatementJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $dispatchId, public readonly string $tenantId) {}

    public function handle(CustomerStatementService $statements, CustomerStatementExporter $exporter, TenantContext $context, TenantClock $clock): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->setTenant($tenant);

        $dispatch = CustomerStatementDispatch::query()->findOrFail($this->dispatchId);

        try {
            $statement = $statements->build($this->tenantId, (string) $dispatch->customer_id, $dispatch->period_from->toDateString(), $dispatch->period_to->toDateString(), $clock->today($tenant));

            Mail::to((string) $dispatch->recipient)->send(new CustomerStatementMail(
                statement: $statement,
                pdf: $exporter->pdf($statement, (string) $dispatch->locale),
                xlsx: $exporter->xlsx($statement, (string) $dispatch->locale),
                locale: (string) $dispatch->locale,
            ));

            $dispatch->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $exception) {
            $dispatch->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000)]);

            throw $exception;
        }
    }
}
