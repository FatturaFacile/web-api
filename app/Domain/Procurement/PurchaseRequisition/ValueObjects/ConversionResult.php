<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\ValueObjects;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use Illuminate\Database\Eloquent\Model;

/**
 * Result of converting approved Purchase Requisition line quantities into an RFQ (Mod 045) or PO (Mod 046).
 */
final readonly class ConversionResult
{
    /**
     * @param  list<PurchaseRequisitionDocumentLink>  $links
     * @param  list<PurchaseRequisition>  $updatedRequisitions
     */
    public function __construct(
        public string $targetType,
        public string $targetDocumentId,
        public string $targetDocumentNumber,
        public Model $createdDocument,
        public array $links,
        public array $updatedRequisitions,
    ) {}
}
