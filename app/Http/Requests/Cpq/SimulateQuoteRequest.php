<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use App\Services\Sales\Cpq\QuoteMarginSimulator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimulateQuoteRequest extends FormRequest
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
        $mode = $this->input('mode');

        return [
            'mode' => ['required', Rule::in([QuoteMarginSimulator::ModeDiscount, QuoteMarginSimulator::ModeTargetMargin, QuoteMarginSimulator::ModeTargetPrice])],
            'value' => ['required', 'numeric', 'min:0', $mode === QuoteMarginSimulator::ModeTargetPrice ? 'max:999999999999' : 'max:100'],
            'surcharge_amount' => ['nullable', 'numeric', 'min:0'],
            'setup_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
