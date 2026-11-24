<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

/**
 * @mixin PreservationBatch
 */
final class PreservationBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'jurisdiction' => $this->jurisdiction->value,
            'status' => $this->status->value,
            'document_category' => $this->document_category->value,
            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'item_count' => $this->item_count,
            'total_size_bytes' => $this->total_size_bytes,
            'pdv_sha256' => $this->pdv_sha256,
            'pda_sha256' => $this->pda_sha256,
            'merkle_root_sha256' => $this->merkle_root_sha256,
            'previous_batch_sha256' => $this->previous_batch_sha256,
            'rfc3161_gen_time' => $this->rfc3161_gen_time?->toIso8601String(),
            'external_conservator_id' => $this->external_conservator_id,
            'retention_until' => $this->retention_until?->toDateString(),
            'sealed_at' => $this->sealed_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'has_timestamp' => $this->rfc3161_token_path !== null,
            'has_rdv' => $this->rdv_receipt_path !== null,
        ];
    }
}
