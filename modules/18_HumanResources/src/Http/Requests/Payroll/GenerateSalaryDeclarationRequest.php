<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;

final class GenerateSalaryDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SalaryDeclaration::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'payroll_run_ids' => ['nullable', 'array'],
            'payroll_run_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
