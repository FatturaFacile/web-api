<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\ServiceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceReport
 */
class PortalServiceReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'report_date' => $this->report_date?->toDateString(),
            'site_address' => $this->site_address,
            'customer_notes' => $this->customer_notes,
            'total_hours' => (float) $this->total_hours,
            'is_signed' => $this->signed_at !== null,
            'signed_at' => $this->signed_at?->toISOString(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_of_measure' => $line->unit_of_measure,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->all()),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($att): array => [
                'id' => $att->id,
                'original_name' => $att->original_name,
                'mime_type' => $att->mime_type,
                'size' => $att->size,
            ])->all()),
        ];
    }
}
