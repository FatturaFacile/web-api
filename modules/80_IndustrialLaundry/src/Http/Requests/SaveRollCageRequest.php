<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveRollCageRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'barcode' => [$required, 'string', 'max:60', Rule::unique('ilr_roll_cages', 'barcode')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('id'))],
            'label' => ['nullable', 'string', 'max:160'],
            'tare_weight_kg' => [$required, 'numeric', 'min:0', 'max:100000'],
            'max_gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
