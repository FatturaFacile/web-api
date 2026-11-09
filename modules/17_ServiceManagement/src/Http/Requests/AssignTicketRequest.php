<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class AssignTicketRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_to_id' => ['required', 'uuid', $this->tenantOwns('esm_service_technicians')],
            'service_team_id' => ['nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
        ];
    }
}
