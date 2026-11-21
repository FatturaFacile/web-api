<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Commands;

/**
 * Command to soft-delete a draft Purchase Requisition.
 */
final readonly class DeletePurchaseRequisitionCommand
{
    public function __construct(
        public string $tenantId,
        public string $requisitionId,
        public string $actorUserId,
    ) {}
}
