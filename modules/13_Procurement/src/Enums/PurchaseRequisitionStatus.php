<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a purchase requisition (`purchase_requisitions.status`).
 *
 * draft ─► pending_approval ─► approved ─► sourcing (RFQ issued) ─► converted_to_po
 *   │             └─► rejected ─► draft          └──────────────────────► converted_to_po
 *   draft, pending_approval, approved, sourcing ─► cancelled
 */
enum PurchaseRequisitionStatus: string
{
    use DocumentStatusMachine;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Sourcing = 'sourcing';
    case Ordered = 'converted_to_po';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Approved, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Draft, self::Cancelled],
            self::Rejected => [self::Draft, self::Cancelled],
            self::Approved => [self::Sourcing, self::Ordered, self::Cancelled],
            self::Sourcing => [self::Ordered, self::Approved, self::Cancelled],
            self::Ordered, self::Cancelled => [],
        };
    }
}
