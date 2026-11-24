<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Downstream procurement document type created from a Purchase Requisition line.
 */
enum DocumentLinkTargetType: string
{
    case RfqMod045 = 'rfq_mod_045';
    case PoMod046 = 'po_mod_046';
}
