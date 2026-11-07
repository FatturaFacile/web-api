<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Services\Helpdesk\TicketAssignmentService;

final class AutoAssignTicketRequest extends FormRequest
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
            'team_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'strategy' => ['required', 'string', Rule::in([TicketAssignmentService::RoundRobin, TicketAssignmentService::LeastLoaded])],
        ];
    }
}
