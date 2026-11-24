<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class RecordVisitRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'technician_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
            'service_appointment_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_appointments')],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'work_summary' => ['nullable', 'string'],
            'customer_feedback' => ['nullable', 'string'],
        ];
    }
}
