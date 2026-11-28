<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AdventureParks\Models\PpeInspectionLog;

/**
 * @mixin PpeInspectionLog
 */
final class InspectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ppe_id' => $this->ppe_asset_id,
            'inspection_type' => $this->inspection_type,
            'outcome' => $this->outcome,
            'inspected_on' => $this->inspected_on->toDateString(),
            'inspector_name' => $this->inspector_name,
            'inspector_license_number' => $this->inspector_license_number,
            'signature' => $this->signature,
            'notes' => $this->notes,
            'checklist' => $this->checklist ?? [],
            'next_due_date' => $this->next_due_date?->toDateString(),
        ];
    }
}
