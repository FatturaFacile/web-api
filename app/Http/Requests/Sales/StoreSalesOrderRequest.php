<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Enums\Incoterm;
use App\Enums\SalesOrderStatus;
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
            'incoterm' => ['nullable', Rule::enum(Incoterm::class)],
            'incoterm_place' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(SalesOrderStatus::manualValues())],
        ]);
    }
}
