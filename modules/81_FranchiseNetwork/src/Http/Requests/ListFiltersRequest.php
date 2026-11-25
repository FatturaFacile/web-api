<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class ListFiltersRequest extends FormRequest
{
    use ScopesToTenant;

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
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'store_id' => ['sometimes', 'nullable', 'uuid'],
            'period' => ['sometimes', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
