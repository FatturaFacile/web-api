<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of an asset transfer; at least one destination is required.
 */
final class TransferAssetRequest extends FormRequest
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
            'to_location_id' => ['nullable', 'required_without_all:to_custodian_id,to_project_id,to_cost_center_id', 'uuid', $this->tenantOwns('eam_asset_locations')],
            'to_custodian_id' => ['nullable', 'uuid', $this->tenantMember()],
            'to_project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'to_cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'transfer_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
