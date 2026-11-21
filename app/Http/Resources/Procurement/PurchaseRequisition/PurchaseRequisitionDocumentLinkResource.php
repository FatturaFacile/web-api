<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\PurchaseRequisition;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequisitionDocumentLink
 */
final class PurchaseRequisitionDocumentLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisitionDocumentLink $link */
        $link = $this->resource;

        return [
            'id' => (string) $link->id,
            'purchase_requisition_id' => (string) $link->purchase_requisition_id,
            'purchase_requisition_line_id' => (string) $link->purchase_requisition_line_id,
            'target_document_type' => (string) $link->target_document_type,
            'target_document_id' => (string) $link->target_document_id,
            'target_document_number' => (string) $link->target_document_number,
            'target_document_line_id' => $link->target_document_line_id,
            'converted_quantity' => (string) $link->converted_quantity,
            'created_by_user_id' => $link->created_by_user_id,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
