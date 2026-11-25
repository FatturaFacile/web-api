<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\PassengerTransport\Models\DddArchive;

/**
 * @mixin DddArchive
 */
final class DddArchiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driver_employee_id' => $this->driver_employee_id,
            'driver_name' => $this->whenLoaded('driver', fn (): ?string => $this->driver?->full_name),
            'vehicle_id' => $this->vehicle_id,
            'file_type' => $this->file_type->value,
            'original_name' => $this->original_name,
            'file_size' => $this->file_size,
            'sha256' => $this->sha256,
            'card_number' => $this->card_number,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'infringements_count' => $this->infringements_count,
            'infringements_summary' => $this->infringements_summary ?? [],
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
