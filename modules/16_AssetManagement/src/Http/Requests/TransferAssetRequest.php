<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferAssetRequest extends FormRequest
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
            'to_location_id' => ['nullable', 'uuid', 'exists:eam_asset_locations,id'],
            'to_custodian_id' => ['nullable', 'uuid', 'exists:users,id'],
            'to_project_id' => ['nullable', 'uuid'],
            'transfer_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
