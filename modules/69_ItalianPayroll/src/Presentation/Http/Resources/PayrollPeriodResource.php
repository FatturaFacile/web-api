<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;

/**
 * @mixin ItalianPayrollPeriod
 */
final class PayrollPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'year' => $this->year,
            'month' => $this->month,
            'include_tredicesima' => $this->include_tredicesima,
            'include_quattordicesima' => $this->include_quattordicesima,
            'status' => $this->status->value,
            'export_format' => $this->export_format,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'exported_at' => $this->exported_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'validation_report' => $this->validation_report_json,
        ];
    }
}
