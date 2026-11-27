<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SwissEbill\Application\Services\EbillDeliveryService;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Throwable;

/**
 * Delivers one queued eBill. Transient gateway errors (HTTP 429, 5xx, network) are retried with the exponential backoff of
 * config/swiss_ebill.php; when the attempts run out the transmission becomes failed_technical and can be retried by hand.
 */
final class TransmitEbillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly string $transmissionId, public readonly string $tenantId)
    {
        $this->tries = count((array) config('swiss_ebill.retry_backoff_seconds', [30])) + 1;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('swiss_ebill.retry_backoff_seconds', [30])));
    }

    public function handle(TenantContext $tenants, EbillDeliveryService $delivery): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenants->runWithTenant($tenant, fn () => $delivery->deliver($this->transmissionId));
    }

    public function failed(Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($exception): void {
            $transmission = app(EbillTransmissionRepositoryInterface::class)->find($this->transmissionId);

            if ($transmission !== null) {
                app(EbillDeliveryService::class)->fail($transmission, 'retries_exhausted', $exception->getMessage());
            }
        });
    }
}
