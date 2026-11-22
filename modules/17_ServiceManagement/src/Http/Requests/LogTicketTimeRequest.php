<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Time worked on a ticket, in minutes. Without a technician the one assigned to the ticket is used.
 */
final class LogTicketTimeRequest extends FormRequest
{
    use TenantScopedRules;

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
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'technician_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_billable' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
