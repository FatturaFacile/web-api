<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Sourcing / procurement conversion status of a Purchase Requisition line.
 */
enum RequisitionLineConversionStatus: string
{
    case Unconverted = 'unconverted';
    case PartiallyConverted = 'partially_converted';
    case FullyConverted = 'fully_converted';
}
