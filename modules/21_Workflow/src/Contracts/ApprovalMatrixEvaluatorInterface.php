<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\ApprovalMatrix;

interface ApprovalMatrixEvaluatorInterface
{
    /**
     * Resolves the matching matrix tiers for an entity based on criteria rules.
     *
     * @return array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>
     */
    public function evaluate(ApprovalMatrix $matrix, Model $entity, array $context): array;

    /**
     * The tiers of the first active matrix of the tenant that matches, trying matrices bound to a cost centre before
     * general ones. Null when the tenant has no active matrix for this entity type.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>|null
     */
    public function tiersFor(string $tenantId, string $entityType, Model $entity, array $context): ?array;
}
