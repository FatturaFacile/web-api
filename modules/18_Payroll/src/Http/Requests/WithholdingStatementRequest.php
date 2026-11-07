<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Models\PayrollWithholdingStatement;

final class WithholdingStatementRequest extends FormRequest
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
            'year' => ['required', 'integer', 'between:2000,2100'],
            'period_type' => ['required', Rule::in(PayrollWithholdingStatement::PeriodTypes)],
            'period' => ['required', 'integer', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
                $maximum = match ($this->input('period_type')) {
                    PayrollWithholdingStatement::Monthly => 12,
                    PayrollWithholdingStatement::Quarterly => 4,
                    default => 1,
                };

                if ((int) $value > $maximum) {
                    $fail("Il periodo non può superare {$maximum}.");
                }
            }],
            'canton' => [Rule::requiredIf($this->isMethod('post') || $this->routeIs('api.v1.payroll.exports.withholding-statement')), 'nullable', Rule::in(PayrollRules::Cantons)],
        ];
    }
}
