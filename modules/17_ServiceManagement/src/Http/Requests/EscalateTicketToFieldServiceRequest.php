<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the escalation of a helpdesk ticket to a field service work order.
 */
final class EscalateTicketToFieldServiceRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access:service middleware.
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
            'diagnostic_notes' => ['nullable', 'string', 'max:5000'],
            'scheduled_start' => ['nullable', 'date'],
            'technician_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
        ];
    }
}
