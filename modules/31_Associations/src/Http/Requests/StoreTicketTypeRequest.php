<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTicketTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'price' => ['sometimes', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
