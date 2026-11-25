<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;

final class SimulateScaleRequest extends FormRequest
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
            'tier_mode' => ['required', Rule::in(TierMode::values())],
            'volume_mode' => ['nullable', Rule::in(VolumeMode::values())],
            'baseline_value' => ['nullable', 'numeric', 'min:0'],
            'tiers' => ['required', 'array', 'min:1', 'max:30'],
            'tiers.*.threshold' => ['required', 'numeric', 'min:0'],
            'tiers.*.rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.flat_amount' => ['nullable', 'numeric', 'min:0'],
            'volumes' => ['required', 'array', 'min:1', 'max:50'],
            'volumes.*' => ['required', 'numeric', 'min:0'],
        ];
    }
}
