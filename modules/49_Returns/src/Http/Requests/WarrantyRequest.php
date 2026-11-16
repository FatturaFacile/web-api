<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\Warranty;

/**
 * A warranty of the registry: unit or batch, product, customer, period and coverage.
 */
final class WarrantyRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'product_id' => ['required', 'uuid'],
            'contact_id' => ['required', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'serial_number' => ['nullable', 'string', 'max:100', 'required_without:lot_number'],
            'lot_number' => ['nullable', 'string', 'max:100', 'required_without:serial_number'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'coverage_type' => ['sometimes', Rule::in(Warranty::CoverageTypes)],
            'coverage_terms' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->isMethod('PUT')) {
            return [
                'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'ends_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'coverage_type' => $rules['coverage_type'],
                'coverage_terms' => $rules['coverage_terms'],
            ];
        }

        return $rules;
    }
}
