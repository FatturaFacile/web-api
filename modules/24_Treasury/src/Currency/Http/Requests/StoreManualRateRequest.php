<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * A manual or contract rate: a six-decimal positive rate for a pair and day; the reason is mandatory for a contract rate.
 */
final class StoreManualRateRequest extends FormRequest
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
            'base' => ['required', 'string', 'size:3', 'alpha'],
            'quote' => ['required', 'string', 'size:3', 'alpha', 'different:base'],
            'rate' => ['required', 'regex:/^\d{1,10}(\.\d{1,6})?$/', 'not_in:0,0.0,0.00,0.000,0.0000,0.00000,0.000000'],
            'valid_on' => ['required', 'date_format:Y-m-d'],
            'rate_type' => ['sometimes', 'string', Rule::in(RateType::values())],
            'reason' => ['required_if:rate_type,contract', 'nullable', 'string', 'max:500'],
            'contract_reference' => ['nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'base' => is_string($this->input('base')) ? strtoupper(trim($this->input('base'))) : $this->input('base'),
            'quote' => is_string($this->input('quote')) ? strtoupper(trim($this->input('quote'))) : $this->input('quote'),
            'rate' => is_int($this->input('rate')) || is_float($this->input('rate')) ? (string) $this->input('rate') : $this->input('rate'),
        ]);
    }
}
