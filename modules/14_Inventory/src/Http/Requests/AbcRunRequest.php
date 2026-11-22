<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * An ABC run: every parameter defaults to the tenant's counting policy; `apply = false` stores the run without
 * changing the class of the items (a what-if).
 */
final class AbcRunRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'period_days' => ['sometimes', 'integer', 'min:1', 'max:3660'],
            'a_share' => ['sometimes', 'numeric', 'gt:0', 'lt:100'],
            'b_share' => ['sometimes', 'numeric', 'gt:0', 'lte:100', 'gt:a_share'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('warehouses')],
            'apply' => ['sometimes', 'boolean'],
        ];
    }
}
