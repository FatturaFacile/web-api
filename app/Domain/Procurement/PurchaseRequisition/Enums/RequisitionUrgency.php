<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Priority / urgency level of a Purchase Requisition.
 */
enum RequisitionUrgency: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
}
