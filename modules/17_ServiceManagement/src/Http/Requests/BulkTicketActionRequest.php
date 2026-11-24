<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskBulkService;

final class BulkTicketActionRequest extends FormRequest
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
            'ticket_ids' => ['required', 'array', 'min:1', 'max:100'],
            'ticket_ids.*' => ['uuid', 'distinct', $this->tenantOwns('esm_tickets')],
            'action' => ['required', 'string', Rule::in(HelpdeskBulkService::Actions)],
            'status' => ['required_if:action,status', 'nullable', 'string', Rule::in(TicketStatus::settableValues())],
            'priority' => ['required_if:action,priority', 'nullable', 'string', 'in:low,medium,high,critical'],
            'assigned_team_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'assigned_technician_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
            'tags' => ['required_if:action,add_tags,remove_tags', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'macro_id' => ['required_if:action,macro', 'nullable', 'uuid', $this->tenantOwns('esm_macros')],
        ];
    }
}
