<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Events\WorkflowDelegationActivatedEvent;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Services\WorkflowDelegationResolver;

final class CreateDelegationAction
{
    public function execute(CreateDelegationDTO $dto): WorkflowDelegation
    {
        if ($dto->delegatorUserId === $dto->delegateUserId) {
            throw ValidationException::withMessages(['delegate_user_id' => __('workflow_errors.delegation.same_user')]);
        }

        // Circular delegation defense: check if delegate has already delegated back to delegator
        $circular = WorkflowDelegation::where('delegator_user_id', $dto->delegateUserId)
            ->where('delegate_user_id', $dto->delegatorUserId)
            ->where('is_active', true)
            ->where('ends_at', '>=', now())
            ->exists();

        if ($circular) {
            throw ValidationException::withMessages(['delegate_user_id' => __('workflow_errors.delegation.circular')]);
        }

        // A -> B -> C -> A: a chain that would close on itself is refused (the chain is followed up to three hops).
        $cycleTenant = $dto->tenantId ?? (string) app(TenantContext::class)->getTenantId();

        if (app(WorkflowDelegationResolver::class)->wouldCreateCycle($dto->delegatorUserId, $dto->delegateUserId, $cycleTenant)) {
            throw ValidationException::withMessages(['delegate_user_id' => __('workflow_errors.delegation.cycle')]);
        }

        // One out-of-office window at a time per delegator and scope: two overlapping delegations would leave it unclear
        // who stands in. A delegation for everything overlaps any other.
        $overlapping = WorkflowDelegation::where('delegator_user_id', $dto->delegatorUserId)
            ->where('is_active', true)
            ->where('starts_at', '<', $dto->endsAt)
            ->where('ends_at', '>', $dto->startsAt)
            ->when($dto->scope !== 'all', static fn ($query) => $query->whereIn('scope', [$dto->scope, 'all']))
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages(['starts_at' => __('workflow_errors.delegation.overlap')]);
        }

        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();

        $delegation = WorkflowDelegation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $dto->companyId,
            'delegator_user_id' => $dto->delegatorUserId,
            'delegate_user_id' => $dto->delegateUserId,
            'scope' => $dto->scope,
            'starts_at' => $dto->startsAt,
            'ends_at' => $dto->endsAt,
            'reason' => $dto->reason,
            'document_types' => $dto->documentTypes === [] ? null : $dto->documentTypes,
            'amount_cap' => $dto->amountCap,
            'source_type' => $dto->sourceType,
            'source_id' => $dto->sourceId,
            'is_active' => true,
        ]);

        event(new WorkflowDelegationActivatedEvent(
            aggregateId: (string) $delegation->id,
            delegatorUserId: $dto->delegatorUserId,
            delegateUserId: $dto->delegateUserId,
            startsAt: $dto->startsAt->format('c'),
            endsAt: $dto->endsAt->format('c'),
            tenantId: (string) $tenantId,
            companyId: (string) ($dto->companyId ?? $tenantId)
        ));

        return $delegation;
    }
}
