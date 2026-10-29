<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Models\WorkflowDelegation;

final class CreateDelegationAction
{
    public function execute(CreateDelegationDTO $dto): WorkflowDelegation
    {
        if ($dto->delegatorUserId === $dto->delegateUserId) {
            throw new InvalidArgumentException('Delegator and delegate cannot be the identical user.');
        }

        // Circular delegation defense: check if delegate has already delegated back to delegator
        $circular = WorkflowDelegation::where('delegator_user_id', $dto->delegateUserId)
            ->where('delegate_user_id', $dto->delegatorUserId)
            ->where('is_active', true)
            ->where('ends_at', '>=', now())
            ->exists();

        if ($circular) {
            throw new InvalidArgumentException('Circular delegation rejected: the delegate user has already delegated to you.');
        }

        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();

        return WorkflowDelegation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $dto->companyId,
            'delegator_user_id' => $dto->delegatorUserId,
            'delegate_user_id' => $dto->delegateUserId,
            'scope' => $dto->scope,
            'starts_at' => $dto->startsAt,
            'ends_at' => $dto->endsAt,
            'reason' => $dto->reason,
            'is_active' => true,
        ]);
    }
}
