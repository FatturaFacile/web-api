<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Source type of a Purchase Requisition line item.
 */
enum RequisitionLineItemType: string
{
    case CatalogItem = 'catalog_item';
    case FreeText = 'free_text';
}
