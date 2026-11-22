<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\OeeLossCategory;

/**
 * Validation of a production execution log (downtime and units of a period of a work centre or machine).
 */
final class RecordOeeLogRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_center_id' => ['required', 'uuid', $this->tenantOwns('mfg_work_centers')],
            'machine_center_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_machine_centers')],
            'source' => ['nullable', 'string', 'in:manual,mes,telemetry'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'planned_downtime_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'unplanned_downtime_minutes' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
            'loss_category' => ['nullable', 'string', Rule::in(OeeLossCategory::values())],
            'good_units' => ['nullable', ...self::Quantity],
            'scrap_units' => ['nullable', ...self::Quantity],
            'rework_units' => ['nullable', ...self::Quantity],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
