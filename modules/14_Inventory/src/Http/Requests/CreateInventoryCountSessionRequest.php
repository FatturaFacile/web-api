<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateInventoryCountSessionRequest extends FormRequest
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
            'warehouse_id' => ['required', 'uuid'],
            'count_type' => ['required', 'string', 'in:full_physical,cycle_count_abc,spot_check,zone_sweep'],
            'scheduled_date' => ['required', 'date'],
            'warehouse_zone_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
