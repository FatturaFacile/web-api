<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of an asset registered from a passive invoice line or a purchase order item.
 */
final class AcquireAssetRequest extends FormRequest
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
            'source_type' => ['required', 'string', 'in:passive_invoice_line,purchase_order_item'],
            'source_id' => ['required', 'uuid'],
            'category_id' => ['required', 'uuid', $this->tenantOwns('eam_asset_categories')],
            'name' => ['nullable', 'string', 'max:255'],
            'asset_class_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_classes')],
            'location_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_locations')],
            'custodian_user_id' => ['nullable', 'uuid', $this->tenantMember()],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'salvage_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'warranty_expiry_date' => ['nullable', 'date'],
            'capitalize' => ['nullable', 'boolean'],
            'capitalization_date' => ['nullable', 'date'],
            'in_service_date' => ['nullable', 'date'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
