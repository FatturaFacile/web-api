<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Enums\RateBasis;

final class SaveTariffRateRequest extends FormRequest
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
            'destination_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'min_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'max_weight_kg' => ['sometimes', 'nullable', 'numeric', 'gte:min_weight_kg'],
            'min_volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'max_volume_m3' => ['sometimes', 'nullable', 'numeric', 'gte:min_volume_m3'],
            'basis' => ['required', Rule::enum(RateBasis::class)],
            'rate' => ['required', 'numeric', 'min:0'],
            'minimum_charge' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
