<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the ticket list. Status, priority, source and tag accept a comma separated list.
 */
final class ListTicketsRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'max:200'],
            'priority' => ['sometimes', 'string', 'max:100'],
            'source' => ['sometimes', 'string', 'max:100'],
            'team_id' => ['sometimes', 'uuid'],
            'agent_id' => ['sometimes', 'uuid'],
            'unassigned' => ['sometimes', 'boolean'],
            'tag' => ['sometimes', 'string', 'max:200'],
            'sla_state' => ['sometimes', 'string', 'in:ok,warning,breached,paused'],
            'customer_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'string', 'max:200'],
            'include_merged' => ['sometimes', 'boolean'],
            'view_id' => ['sometimes', 'uuid', $this->tenantOwns('esm_saved_views')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
