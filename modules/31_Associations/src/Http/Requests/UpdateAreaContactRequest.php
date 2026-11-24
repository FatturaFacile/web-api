<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAreaContactRequest extends FormRequest
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
            'email' => ['sometimes', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'array'],
            'address.street' => ['required_with:address', 'string', 'max:255'],
            'address.house_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.postal_code' => ['required_with:address', 'string', 'max:20'],
            'address.city' => ['required_with:address', 'string', 'max:255'],
            'address.state_canton' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
