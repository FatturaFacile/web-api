<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\AnalyticalAccounting\Domain\Enums\DriverType;

/**
 * Validation of an allocation driver definition.
 */
final class DriverRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('analytical_allocation_drivers', 'code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->ignore($id)],
            'name' => ['required', 'string', 'max:160'],
            'driver_type' => ['required', Rule::in(DriverType::values())],
            'unit' => ['nullable', 'string', 'max:24'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
