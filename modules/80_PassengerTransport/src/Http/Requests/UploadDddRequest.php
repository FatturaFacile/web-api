<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class UploadDddRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.(int) config('passenger_transport.max_ddd_kilobytes'), 'extensions:ddd,DDD,c1b,v1b,tgd'],
            'driver_employee_id' => ['nullable', 'uuid', $this->tenantExists('hr_employees')],
            'vehicle_id' => ['nullable', 'uuid', $this->tenantExists('ptr_fleet_vehicles')],
        ];
    }
}
