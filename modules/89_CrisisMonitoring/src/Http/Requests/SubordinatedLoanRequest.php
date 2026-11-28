<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A loan of the Rangrücktritt registry (Art. 725b CO).
 */
final class SubordinatedLoanRequest extends FormRequest
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
            'creditor_name' => ['required', 'string', 'max:150'],
            'creditor_type' => ['required', Rule::in(['shareholder', 'third_party'])],
            'principal' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'interest_rate' => ['required', 'numeric', 'between:0,100'],
            'interest_treatment' => ['required', Rule::in(['deferred', 'paid_current'])],
            'agreement_date' => ['required', 'date_format:Y-m-d'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'deferral_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'scope' => ['required', Rule::in(['principal', 'principal_and_interest'])],
            'status' => ['required', Rule::in(['draft', 'active', 'released'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
