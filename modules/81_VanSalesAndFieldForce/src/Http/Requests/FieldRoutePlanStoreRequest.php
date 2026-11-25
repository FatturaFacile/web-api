<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FieldRoutePlanStoreRequest extends FormRequest
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
            'van_warehouse_id' => ['required', 'uuid'],
            'van_load_manifest_id' => ['nullable', 'uuid'],
            'route_code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'plan_date' => ['required', 'date'],
            'is_recurring' => ['boolean'],
            'salesperson_name' => ['nullable', 'string', 'max:140'],
            'territory_zone' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.sequence' => ['required', 'integer', 'min:1'],
            'stops.*.contact_id' => ['required', 'uuid'],
            'stops.*.customer_name' => ['nullable', 'string', 'max:160'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'stops.*.visit_mode' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
