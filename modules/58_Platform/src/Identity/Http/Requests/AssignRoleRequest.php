<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['uuid'],
            'company_id' => ['nullable', 'uuid'],
        ];
    }
}
