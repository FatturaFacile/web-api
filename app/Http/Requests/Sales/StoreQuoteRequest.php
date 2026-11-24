<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Enums\QuoteStatus;
use Illuminate\Validation\Rule;

class StoreQuoteRequest extends SalesDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'valid_until' => ['nullable', 'date', 'after_or_equal:date'],
            'status' => ['sometimes', Rule::in(QuoteStatus::manualValues())],
            'custom_text' => ['nullable', 'string', 'max:5000'],
            'revision_notes' => ['nullable', 'string', 'max:2000'],
            'allow_optional_selection' => ['sometimes', 'boolean'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.labor_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.configuration_snapshot' => ['nullable', 'array'],
            'items.*.is_optional' => ['sometimes', 'boolean'],
            'items.*.is_selected' => ['sometimes', 'boolean'],
        ]);
    }
}
