<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Models\F24DelegationLine;

/**
 * Taxpayer, payment date and section lines of a Modello F24. The fiscal rules (balancing, section data, tributo codes)
 * are checked when the delegation is validated, so a draft can be saved incomplete.
 */
final class F24DelegationRequest extends FormRequest
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
            'bank_account_id' => ['nullable', 'uuid'],
            'taxpayer_fiscal_code' => ['required', 'string', 'min:11', 'max:16'],
            'taxpayer_name' => ['required', 'string', 'max:60'],
            'taxpayer_first_name' => ['nullable', 'string', 'max:60'],
            'taxpayer_birth_date' => ['nullable', 'date'],
            'taxpayer_birth_place' => ['nullable', 'string', 'max:40'],
            'taxpayer_birth_province' => ['nullable', 'string', 'size:2'],
            'taxpayer_gender' => ['nullable', 'string', Rule::in(['M', 'F', 'm', 'f'])],
            'payment_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.section' => ['required', 'string', Rule::in(F24DelegationLine::SECTIONS)],
            'lines.*.tax_code' => ['required', 'string', 'max:12'],
            'lines.*.institution_code' => ['nullable', 'string', 'max:20'],
            'lines.*.reference_number' => ['nullable', 'string', 'max:20'],
            'lines.*.installment' => ['nullable', 'string', 'max:8'],
            'lines.*.reference_period' => ['nullable', 'string', 'max:8'],
            'lines.*.reference_year' => ['nullable', 'string', 'max:4'],
            'lines.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
