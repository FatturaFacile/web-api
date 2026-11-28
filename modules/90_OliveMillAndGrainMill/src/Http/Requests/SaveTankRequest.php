<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creation and edit of a tank or silo.
 */
final class SaveTankRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40', Rule::unique('mill_tanks', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($id)],
            'description' => ['nullable', 'string', 'max:160'],
            'material' => ['nullable', Rule::in(['INOX_AISI_304', 'INOX_AISI_316', 'CONCRETE', 'OTHER'])],
            'capacity_kg' => ['required', 'numeric', 'gt:0'],
            'capacity_l' => ['nullable', 'numeric', 'gt:0'],
            'temperature_c' => ['nullable', 'numeric', 'min:-10', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'grid_row' => ['nullable', 'integer', 'min:1', 'max:50'],
            'grid_col' => ['nullable', 'integer', 'min:1', 'max:50'],
            'zone_label' => ['nullable', 'string', 'max:60'],
        ];
    }
}
