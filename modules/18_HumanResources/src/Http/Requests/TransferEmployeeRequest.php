<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_department_id' => ['nullable', 'uuid'],
            'to_team_id' => ['nullable', 'uuid'],
            'to_business_unit_id' => ['nullable', 'uuid'],
            'to_cost_center_id' => ['nullable', 'uuid'],
            'to_position_id' => ['nullable', 'uuid'],
            'to_manager_id' => ['nullable', 'uuid'],
            'effective_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
