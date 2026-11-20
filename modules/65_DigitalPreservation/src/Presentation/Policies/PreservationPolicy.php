<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;

/**
 * Tenant check for batches and items; the role check is done by the `module.access:digital_preservation` middleware.
 */
final readonly class PreservationPolicy
{
    public function __construct(private TenantContext $tenantContext) {}

    public function view(User $user, PreservationBatch|PreservationBatchItem $record): bool
    {
        return $this->tenantContext->getTenantId() !== null && $record->tenant_id === $this->tenantContext->getTenantId();
    }

    public function manage(User $user, PreservationBatch|PreservationBatchItem $record): bool
    {
        return $this->view($user, $record);
    }
}
