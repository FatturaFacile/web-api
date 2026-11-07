<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class StoreTicketRequest extends FormRequest
{
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
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'uuid', $this->ofTenant('contacts')],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'channel' => ['nullable', 'string', 'in:portal,email,phone,chat,api,field'],
            'service_category_id' => ['nullable', 'uuid', $this->ofTenant('esm_service_categories')],
            'service_contract_id' => ['nullable', 'uuid', $this->ofTenant('esm_service_contracts')],
            'sla_policy_id' => ['nullable', 'uuid', $this->ofTenant('esm_sla_policies')],
            'assigned_to_id' => ['nullable', 'uuid', $this->ofTenant('esm_service_technicians')],
            'service_team_id' => ['nullable', 'uuid', $this->ofTenant('esm_service_teams')],
        ];
    }

    /**
     * A row of the active company, or a generic one without company.
     */
    private function ofTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
