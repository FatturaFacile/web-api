<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Calculation\WageTypeKind;

final class SaveWageTypeRequest extends FormRequest
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
            'code' => ['required', 'string', 'regex:/^[0-9A-Z]{1,6}$/'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(WageTypeKind::All)],
            'subject_to_ahv' => ['sometimes', 'boolean'],
            'subject_to_alv' => ['sometimes', 'boolean'],
            'subject_to_uvg' => ['sometimes', 'boolean'],
            'subject_to_ktg' => ['sometimes', 'boolean'],
            'subject_to_bvg' => ['sometimes', 'boolean'],
            'subject_to_withholding_tax' => ['sometimes', 'boolean'],
            'is_irregular' => ['sometimes', 'boolean'],
            'certificate_box' => ['sometimes', 'nullable', 'string', 'max:10'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
