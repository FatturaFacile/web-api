<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\LeadLifecycleStage;

final class CaptureLeadRequest extends FormRequest
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
            'lead_code' => ['nullable', 'string', 'max:40'],
            'source_channel' => ['nullable', 'string', 'max:64'],
            'full_name' => ['required', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:48'],
            'country' => ['nullable', 'string', 'size:2'],
            'fiscal_identifier' => ['nullable', 'string', 'max:40'],
            'demographic_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'behavioral_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'firmographic_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'lifecycle_stage' => ['nullable', Rule::in(LeadLifecycleStage::values())],
            'assigned_rep_name' => ['nullable', 'string', 'max:120'],
            'assigned_territory' => ['nullable', 'string', 'max:80'],
            'utm_metadata' => ['nullable', 'array'],
        ];
    }
}
