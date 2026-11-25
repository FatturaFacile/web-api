<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Models\Notice;

final class OfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Notice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'postal_code_from' => ['nullable', 'string', 'max:10'],
            'postal_code_to' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:120'],
            'canton' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
