<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FiscalRetail\Models\RetailSessionReport;

/**
 * A stored X or Z report: the figures of the session as they were when it was produced.
 *
 * @mixin RetailSessionReport
 */
final class RetailSessionReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'number' => $this->number,
            'pos_session_id' => $this->pos_session_id,
            'business_date' => $this->business_date->toDateString(),
            'totals' => $this->totals,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
