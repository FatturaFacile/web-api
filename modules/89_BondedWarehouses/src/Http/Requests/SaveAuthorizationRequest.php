<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Enums\WarehouseSubtype;

final class SaveAuthorizationRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'authorization_code' => ['required', 'string', 'max:60', Rule::unique('customs_warehouse_authorizations', 'authorization_code')->where('tenant_id', $tenantId)->ignore($id)->whereNull('deleted_at')],
            'customs_office_code' => ['required', 'string', 'max:30'],
            'regime_type' => ['required', Rule::enum(RegimeType::class)],
            'warehouse_subtype' => ['required', Rule::enum(WarehouseSubtype::class)],
            'cgu_guarantee_reference' => ['nullable', 'string', 'max:80'],
            'cgu_max_ceiling_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency' => ['required', Rule::in(['EUR', 'CHF'])],
            'aeo_certificate_number' => ['nullable', 'string', 'max:60', 'required_unless:aeo_status,None'],
            'aeo_status' => ['required', Rule::enum(AeoStatus::class)],
            'aeo_next_audit_date' => ['nullable', 'date'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
