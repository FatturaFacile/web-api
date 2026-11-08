<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of an operational asset class.
 */
final class StoreAssetClassRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:60', Rule::unique('eam_asset_classes', 'code')->where('tenant_id', $this->tenantId())],
            'name' => ['required', 'string', 'max:150'],
            'criticality' => ['nullable', 'string', 'in:low,medium,high,mission_critical'],
            'default_maintenance_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'requires_custodian' => ['nullable', 'boolean'],
            'requires_calibration' => ['nullable', 'boolean'],
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
