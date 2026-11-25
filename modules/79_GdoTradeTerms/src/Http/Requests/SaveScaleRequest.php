<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GdoTradeTerms\Domain\Enums\BaselineSource;
use Modules\GdoTradeTerms\Domain\Enums\RebateBasis;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;

final class SaveScaleRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40'],
            'name' => [$required, 'string', 'max:160'],
            'tier_mode' => [$required, Rule::in(TierMode::values())],
            'basis' => [$required, Rule::in(RebateBasis::values())],
            'volume_mode' => [$required, Rule::in(VolumeMode::values())],
            'baseline_source' => ['nullable', Rule::in(BaselineSource::values())],
            'baseline_value' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['uuid'],
            'brand_ids' => ['nullable', 'array'],
            'brand_ids.*' => ['uuid'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['uuid'],
            'delivery_point_ids' => ['nullable', 'array'],
            'delivery_point_ids.*' => ['uuid'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tiers' => ['sometimes', 'array', 'max:30'],
            'tiers.*.threshold' => ['required', 'numeric', 'min:0'],
            'tiers.*.rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.flat_amount' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.label' => ['nullable', 'string', 'max:160'],
        ];
    }
}
