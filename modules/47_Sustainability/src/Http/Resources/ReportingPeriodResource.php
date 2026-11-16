<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgReportingPeriod;

/**
 * A reporting period.
 *
 * @mixin EsgReportingPeriod
 */
final class ReportingPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'status' => $this->status,
            'revenue' => $this->revenue,
            'currency' => $this->currency,
            'employees_fte' => $this->employees_fte,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
