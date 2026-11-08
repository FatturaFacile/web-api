<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of the descriptive, warranty and insurance data of an asset; values change only through the lifecycle operations.
 */
final class UpdateFixedAssetRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'barcode_tag' => ['nullable', 'string', 'max:100'],
            'condition' => ['sometimes', 'string', 'in:new,excellent,good,fair,poor,damaged'],
            'asset_class_id' => ['nullable', 'uuid', $this->tenantOwns('eam_asset_classes')],
            'replacement_cost' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'warranty_expiry_date' => ['nullable', 'date'],
            'warranty_provider' => ['nullable', 'string', 'max:150'],
            'insurance_provider' => ['nullable', 'string', 'max:150'],
            'insurance_policy_number' => ['nullable', 'string', 'max:100'],
            'insured_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'insurance_expiry_date' => ['nullable', 'date'],
            'specifications' => ['nullable', 'array'],
        ];
    }
}
