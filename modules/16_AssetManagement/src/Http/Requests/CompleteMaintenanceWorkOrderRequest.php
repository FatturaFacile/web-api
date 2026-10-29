<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteMaintenanceWorkOrderRequest extends FormRequest
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
            'downtime_hours' => ['nullable', 'numeric', 'min:0'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'parts_cost' => ['nullable', 'numeric', 'min:0'],
            'external_service_cost' => ['nullable', 'numeric', 'min:0'],
            'capitalize_as_upgrade' => ['nullable', 'boolean'],
            'condition_after_maintenance' => ['nullable', 'string', 'in:new,excellent,good,fair,poor,damaged'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'parts_consumed' => ['nullable', 'array'],
        ];
    }
}
