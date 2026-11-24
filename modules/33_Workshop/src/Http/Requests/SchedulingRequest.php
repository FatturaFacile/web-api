<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bays and the planned slots of the schedule. Each route names the payload it validates; a PUT only validates the
 * fields it sends.
 */
final class SchedulingRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Who may call the endpoint is decided by the module.access middleware and the policy check of the controller.
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
        $required = $this->isMethod('PUT') ? 'sometimes' : 'required';

        return match (str_replace('api.v1.workshop.', '', (string) $this->route()?->getName())) {
            'bays.store', 'bays.update' => [
                'code' => [$required, 'string', 'max:30'],
                'name' => [$required, 'string', 'max:120'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'schedule.index' => [
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after:from'],
                'technician_user_id' => ['sometimes', 'uuid'],
                'bay_id' => ['sometimes', 'uuid'],
                'folder_id' => ['sometimes', 'uuid'],
            ],
            'schedule.store', 'schedule.update' => [
                'folder_id' => [$this->isMethod('PUT') ? 'prohibited' : 'required', 'uuid', $this->tenantExists('workshop_folders')],
                'technician_user_id' => ['nullable', 'uuid', $this->tenantMember()],
                'bay_id' => ['nullable', 'uuid', $this->tenantExists('workshop_bays')],
                'starts_at' => [$required, 'date'],
                'ends_at' => [$required, 'date', 'after:starts_at'],
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };
    }
}
