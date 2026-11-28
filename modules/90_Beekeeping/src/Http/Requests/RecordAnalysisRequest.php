<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

final class RecordAnalysisRequest extends BeekeepingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tank_code' => ['nullable', 'string', 'max:40'],
            'decanted_on' => ['nullable', 'date'],
            'moisture_pct' => ['nullable', 'numeric', 'between:10,40'],
            'hmf_mg_kg' => ['nullable', 'numeric', 'between:0,500'],
            'organoleptic_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
