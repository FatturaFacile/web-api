<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Validation\Rule;

class StoreSalesOrderRequest extends SalesDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'status' => ['sometimes', Rule::in(['draft', 'confirmed', 'in_production', 'shipped', 'completed', 'cancelled'])],
        ]);
    }
}
