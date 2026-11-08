<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of the completion of a work order.
 */
final class CompleteMaintenanceWorkOrderRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
     */
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
            'downtime_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'labor_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'parts_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'external_service_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'capitalize_as_upgrade' => ['nullable', 'boolean'],
            'condition_after_maintenance' => ['nullable', 'string', 'in:new,excellent,good,fair,poor,damaged'],
            'meter_reading' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'resolution_notes' => ['nullable', 'string', 'max:5000'],
            'parts_consumed' => ['nullable', 'array', 'max:200'],
            'parts_consumed.*.description' => ['required_with:parts_consumed', 'string', 'max:255'],
            'parts_consumed.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
