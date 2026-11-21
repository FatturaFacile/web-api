<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;

/**
 * @property PpeDistributionRecord $resource
 */
final class PpeDistributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'product_id' => $record->product_id,
            'warehouse_id' => $record->warehouse_id,
            'lot_or_serial_id' => $record->lot_or_serial_id,
            'quantity' => $record->quantity,
            'ce_marking_standard' => $record->ce_marking_standard,
            'handed_over_at' => $record->handed_over_at->toIso8601String(),
            'replacement_due_at' => $record->replacement_due_at?->toDateString(),
            'employee_signature_hash' => $record->employee_signature_hash,
            'verbale_consegna_pdf_id' => $record->verbale_consegna_pdf_id,
            'delivery_status' => $record->delivery_status->value,
        ];
    }
}
