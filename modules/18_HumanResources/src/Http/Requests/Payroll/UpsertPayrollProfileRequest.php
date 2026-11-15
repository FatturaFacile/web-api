<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\Payroll\SwissCanton;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Rules\ValidAhvNumber;

final class UpsertPayrollProfileRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const PERMIT_TYPES = ['B', 'C', 'CI', 'F', 'G', 'L', 'N', 'S'];

    /**
     * @var list<string>
     */
    public const CIVIL_STATUSES = ['single', 'married', 'divorced', 'widowed', 'separated', 'registered_partnership', 'dissolved_partnership'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', PayrollProfile::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cantons = SwissCanton::values();

        return [
            'ahv_number' => ['nullable', 'string', new ValidAhvNumber],
            'work_canton' => ['required', 'string', Rule::in($cantons)],
            'residence_canton' => ['nullable', 'string', Rule::in($cantons)],
            'residence_country' => ['nullable', 'string', 'size:2', 'alpha'],
            'residence_municipality_bfs' => ['nullable', 'string', 'regex:/^\d{1,5}$/'],
            'permit_type' => ['nullable', 'string', Rule::in(self::PERMIT_TYPES)],
            'civil_status' => ['nullable', 'string', Rule::in(self::CIVIL_STATUSES)],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'church_tax' => ['sometimes', 'boolean'],
            'qst_tariff_code' => ['nullable', 'string', 'regex:/^[A-Z]\d{1,2}[YN]$/'],
            'qst_canton' => ['nullable', 'string', Rule::in($cantons)],
            'bvg_insured' => ['sometimes', 'boolean'],
            'bvg_plan_code' => ['nullable', 'string', 'max:40'],
            'uvg_class' => ['nullable', 'string', 'max:20'],
            'ktg_insured' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
