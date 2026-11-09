<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreIncidentRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical,catastrophic'],
            'impact' => ['nullable', 'string', 'in:minor,moderate,significant,extensive'],
            'urgency' => ['nullable', 'string', 'in:low,medium,high,immediate'],
            'service_category_id' => ['nullable', 'uuid', $this->tenantExists('esm_service_categories')],
            'affected_asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
        ];
    }
}
