<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of an asset location.
 */
final class StoreAssetLocationRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policies of the controller.
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
        return [
            'code' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:60', Rule::unique('eam_asset_locations', 'code')->where('tenant_id', $this->tenantId())->ignore($this->route('id'))],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'parent_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_locations')],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'location_type' => ['nullable', 'string', 'in:facility,building,floor,room,yard,mobile,project_site'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The active tenant.
     */
    private function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }
}
