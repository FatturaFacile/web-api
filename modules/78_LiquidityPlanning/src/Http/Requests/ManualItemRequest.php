<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\LiquidityPlanning\Models\ForecastManualItem;

/**
 * A manual forecast item (an assumption of the CFO).
 */
final class ManualItemRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:150'],
            'tier' => ['required', Rule::in(['certain', 'probable', 'estimated'])],
            'category' => ['required', Rule::in(ForecastManualItem::Categories)],
            'direction' => ['required', Rule::in(['inflow', 'outflow'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'first_date' => ['required', 'date'],
            'frequency' => ['required', Rule::in(ForecastManualItem::Frequencies)],
            'end_date' => ['nullable', 'date', 'after_or_equal:first_date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
