<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** What a proposal asks for: a new order, or advice on an open order. */
enum ProposalType: string
{
    case PlannedPurchaseRequisition = 'PlannedPurchaseRequisition';
    case PlannedProductionOrder = 'PlannedProductionOrder';
    case PlannedSubcontractOrder = 'PlannedSubcontractOrder';
    case RescheduleInExpedite = 'RescheduleIn_Expedite';
    case RescheduleOutDefer = 'RescheduleOut_Defer';
    case CancelExcessOrder = 'CancelExcessOrder';

    /** Proposals that create a new order (as opposed to advice on an existing one). */
    public function isNewOrder(): bool
    {
        return match ($this) {
            self::PlannedPurchaseRequisition, self::PlannedProductionOrder, self::PlannedSubcontractOrder => true,
            default => false,
        };
    }
}
