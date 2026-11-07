<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectSalesQuoteRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
