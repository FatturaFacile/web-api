<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class TransferAssetRequest extends FormRequest
{
    use TenantScopedRules;

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
            'to_location_id' => ['nullable', 'uuid', $this->tenantExists('eam_asset_locations')],
            'to_custodian_id' => ['nullable', 'uuid', $this->tenantMember()],
            'to_project_id' => ['nullable', 'uuid'],
            'transfer_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
