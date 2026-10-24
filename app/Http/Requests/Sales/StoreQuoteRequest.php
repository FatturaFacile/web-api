<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

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
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'accepted', 'rejected', 'expired'])],
            'custom_text' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
