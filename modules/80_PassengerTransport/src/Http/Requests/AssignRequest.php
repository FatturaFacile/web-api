<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class AssignRequest extends FormRequest
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
        return [
            'vehicle_id' => ['required', 'uuid', $this->tenantExists('ptr_fleet_vehicles')],
            'driver_employee_id' => ['required', 'uuid', $this->tenantExists('hr_employees')],
            'planned_break_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'acknowledge_warnings' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
