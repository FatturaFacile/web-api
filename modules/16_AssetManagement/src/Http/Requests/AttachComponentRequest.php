<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of a new component of an asset.
 */
final class AttachComponentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'component_code' => ['nullable', 'string', 'max:60'],
            'component_asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'cost_allocation' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'condition' => ['nullable', 'string', 'in:new,excellent,good,fair,poor,damaged'],
            'installed_date' => ['nullable', 'date'],
        ];
    }
}
