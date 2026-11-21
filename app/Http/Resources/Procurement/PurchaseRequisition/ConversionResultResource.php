<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\PurchaseRequisition;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\ConversionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversionResult
 */
final class ConversionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ConversionResult $result */
        $result = $this->resource;

        return [
            'target_type' => $result->targetType,
            'target_document_id' => $result->targetDocumentId,
            'target_document_number' => $result->targetDocumentNumber,
            'links' => PurchaseRequisitionDocumentLinkResource::collection($result->links),
            'requisitions' => array_map(
                static fn (PurchaseRequisition $pr): array => [
                    'id' => (string) $pr->id,
                    'requisition_number' => (string) $pr->requisition_number,
                    'status' => (string) $pr->status,
                    'converted_at' => $pr->converted_at?->toIso8601String(),
                ],
                $result->updatedRequisitions
            ),
        ];
    }
}
