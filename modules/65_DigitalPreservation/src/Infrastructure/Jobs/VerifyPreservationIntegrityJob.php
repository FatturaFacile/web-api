<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DigitalPreservation\Application\Commands\VerifyPreservationIntegrityCommand;
use Modules\DigitalPreservation\Application\Handlers\VerifyPreservationIntegrityHandler;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

/**
 * Periodic re-hash of the preserved files and check of the Merkle roots, the batch chain and the audit chain, to catch
 * bit-rot and tampering. Failures raise an `integrity_alert` entry and an IntegrityVerificationFailed event.
 */
final class VerifyPreservationIntegrityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly ?string $tenantId = null) {}

    public function handle(TenantContext $context, VerifyPreservationIntegrityHandler $handler): void
    {
        $tenantIds = PreservationBatch::withoutGlobalScopes()->where('status', '!=', 'draft')
            ->when($this->tenantId, fn ($q, $id) => $q->where('tenant_id', $id))->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                continue;
            }

            $context->runWithTenant($tenant, function () use ($handler): void {
                foreach (PreservationBatch::where('status', '!=', 'draft')->orderBy('sealed_at')->get() as $batch) {
                    $handler->handle(new VerifyPreservationIntegrityCommand(batchId: $batch->id));
                }
            });
        }
    }
}
