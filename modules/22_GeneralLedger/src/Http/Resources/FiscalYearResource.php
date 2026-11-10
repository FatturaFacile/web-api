<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\FiscalYear;

/**
 * @mixin FiscalYear
 */
final class FiscalYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tenant_id' => (string) $this->tenant_id,
            'year_code' => $this->year_code,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'is_locked' => (bool) $this->is_locked,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'reopened_by' => $this->reopened_by,
            'reopen_reason' => $this->reopen_reason,
            'agm_approved_at' => $this->agm_approved_at?->toIso8601String(),
            'agm_approved_by' => $this->agm_approved_by,
            'agm_resolution_reference' => $this->agm_resolution_reference,
            'periods' => FiscalPeriodResource::collection($this->whenLoaded('periods')),
        ];
    }
}
