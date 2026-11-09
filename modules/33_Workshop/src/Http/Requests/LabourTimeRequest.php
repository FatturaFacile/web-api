<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Clocking and entering labour time, and billing it. Each route names the payload it validates.
 */
final class LabourTimeRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
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
        return match (str_replace('api.v1.workshop.', '', (string) $this->route()?->getName())) {
            'folders.time.start' => [
                'technician_user_id' => ['sometimes', 'uuid', $this->tenantMember()],
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            'folders.time.store' => [
                'technician_user_id' => ['required', 'uuid', $this->tenantMember()],
                'started_at' => ['required', 'date'],
                'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            'folders.time.bill' => [
                'hourly_rate' => ['required', 'decimal:0,4', 'gt:0'],
                'tax_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
                'description' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };
    }
}
