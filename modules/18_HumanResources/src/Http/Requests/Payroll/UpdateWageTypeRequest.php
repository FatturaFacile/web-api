<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\Payroll\WageTypeCategory;
use Modules\HumanResources\Models\Payroll\WageType;

/**
 * The wage type code is immutable once created (payslip lines reference it); every other field can change.
 */
final class UpdateWageTypeRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'category' => ['sometimes', 'string', Rule::in(WageTypeCategory::values())],
            'is_ahv_subject' => ['sometimes', 'boolean'],
            'is_alv_subject' => ['sometimes', 'boolean'],
            'is_bvg_subject' => ['sometimes', 'boolean'],
            'is_uvg_subject' => ['sometimes', 'boolean'],
            'is_qst_subject' => ['sometimes', 'boolean'],
            'elm_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
