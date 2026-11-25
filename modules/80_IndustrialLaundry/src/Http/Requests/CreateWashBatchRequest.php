<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;

final class CreateWashBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'batch_code' => ['nullable', 'string', 'max:60'],
            'program_code' => ['required', 'string', 'max:40'],
            'rabc_risk_zone' => ['nullable', Rule::in(RabcRiskZone::values())],
            'scan_session_ids' => ['required', 'array', 'min:1', 'max:200'],
            'scan_session_ids.*' => ['uuid', 'distinct'],
            'operator_name' => ['nullable', 'string', 'max:160'],
            'washed_at' => ['nullable', 'date'],
        ];
    }
}
