<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Commands;

use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;

/**
 * Command to approve or reject a pending Purchase Requisition via in-app UI or signed single-use 1-click token.
 */
final readonly class ProcessRequisitionApprovalCommand
{
    public function __construct(
        public ?string $tenantId,
        public ?string $requisitionId,
        public ?string $actorUserId,
        public string $action,
        public ?string $comment,
        public ApprovalChannel $channel,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $oneClickToken = null,
    ) {}
}
