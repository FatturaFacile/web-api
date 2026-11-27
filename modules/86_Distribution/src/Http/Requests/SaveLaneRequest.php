<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Domain\Enums\TransportMode;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * A distribution lane between two warehouses.
 */
final class SaveLaneRequest extends FormRequest
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
        $ignore = $this->route('id');

        return [
            'source_warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'destination_warehouse_id' => [
                'required', 'uuid', 'different:source_warehouse_id', $this->tenantExists('warehouses'),
                Rule::unique('distribution_lanes', 'destination_warehouse_id')
                    ->where('tenant_id', app(TenantContext::class)->getTenantId())
                    ->where('source_warehouse_id', $this->input('source_warehouse_id'))
                    ->where('transport_mode', $this->input('transport_mode', 'road'))
                    ->whereNull('deleted_at')
                    ->ignore(is_string($ignore) ? $ignore : null),
            ],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'lead_time_hours' => ['nullable', 'integer', 'min:0', 'max:23'],
            'transport_mode' => ['nullable', 'string', Rule::in(TransportMode::values())],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
