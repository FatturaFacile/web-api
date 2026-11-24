<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Enums\OeeCalculationGranularity;

/**
 * Validation of an OEE query: a work centre and/or a machine over a period.
 */
final class OeeQueryRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_center_id' => ['required_without:machine_center_id', 'nullable', 'uuid', $this->tenantOwns('mfg_work_centers')],
            'machine_center_id' => ['nullable', 'uuid', $this->tenantOwns('mfg_machine_centers')],
            'from_datetime' => ['required', 'date'],
            'to_datetime' => ['required', 'date', 'after:from_datetime'],
            'granularity' => ['nullable', 'string', Rule::in(OeeCalculationGranularity::values())],
        ];
    }
}
