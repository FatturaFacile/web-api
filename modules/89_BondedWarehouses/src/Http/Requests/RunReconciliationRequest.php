<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;

final class RunReconciliationRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'authorization_id' => ['required', 'uuid', Rule::exists('customs_warehouse_authorizations', 'id')->where('tenant_id', $tenantId)],
            'audit_date' => ['sometimes', 'date'],
            'partner_checks' => ['sometimes', 'array', 'max:50'],
            'partner_checks.*.partner_or_carrier_name' => ['required', 'string', 'max:190'],
            'partner_checks.*.partner_aeo_status' => ['required', Rule::enum(AeoStatus::class)],
            'partner_checks.*.security_seal_verified' => ['required', 'boolean'],
            'partner_checks.*.findings' => ['nullable', 'string', 'max:2000'],
            'partner_checks.*.corrective_action' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
