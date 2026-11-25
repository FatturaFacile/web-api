<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Plm\Enums\ApprovalDepartment;

final class DepartmentSignoffRequest extends PlmFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'department' => ['required', Rule::in(ApprovalDepartment::values())],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'actor_role' => ['nullable', 'string', 'max:64'],
        ];
    }
}
