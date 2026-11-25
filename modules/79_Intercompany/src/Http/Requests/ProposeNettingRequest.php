<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ProposeNettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intercompany.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_a_tenant_id' => ['required', 'uuid'],
            'entity_b_tenant_id' => ['required', 'uuid', 'different:entity_a_tenant_id'],
            'as_of_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
