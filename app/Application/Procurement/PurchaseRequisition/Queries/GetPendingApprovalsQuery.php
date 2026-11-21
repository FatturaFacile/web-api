<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Queries;

use App\Models\User;

/**
 * Query for Purchase Requisitions currently awaiting approval from the authenticated user.
 */
final readonly class GetPendingApprovalsQuery
{
    public function __construct(
        public string $tenantId,
        public User $user,
        public int $page = 1,
        public int $perPage = 20,
    ) {}
}
