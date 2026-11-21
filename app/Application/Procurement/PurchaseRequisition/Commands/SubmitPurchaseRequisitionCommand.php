<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Commands;

use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;

/**
 * Command to submit a Purchase Requisition for real-time budget checking and multi-level approval routing.
 */
final readonly class SubmitPurchaseRequisitionCommand
{
    public function __construct(
        public string $tenantId,
        public string $requisitionId,
        public string $actorUserId,
        public ApprovalChannel $channel = ApprovalChannel::Web,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public bool $acknowledgeBudgetWarning = false,
    ) {}
}
