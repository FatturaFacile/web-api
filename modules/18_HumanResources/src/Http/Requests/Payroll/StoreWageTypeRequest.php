<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\Payroll\WageTypeCategory;
use Modules\HumanResources\Models\Payroll\WageType;

final class StoreWageTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WageType::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('hr_ch_wage_types', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::in(WageTypeCategory::values())],
            'is_ahv_subject' => ['sometimes', 'boolean'],
            'is_alv_subject' => ['sometimes', 'boolean'],
            'is_bvg_subject' => ['sometimes', 'boolean'],
            'is_uvg_subject' => ['sometimes', 'boolean'],
            'is_qst_subject' => ['sometimes', 'boolean'],
            'elm_code' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
