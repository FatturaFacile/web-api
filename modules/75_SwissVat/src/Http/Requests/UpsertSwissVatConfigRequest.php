<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertSwissVatConfigRequest extends FormRequest
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
            'uid_mwst' => ['nullable', 'string', 'max:32'],
            'accounting_method' => ['required', 'string', Rule::in(['effective', 'saldosteuersatz'])],
            'consideration_basis' => ['required', 'string', Rule::in(['agreed', 'received'])],
            'reporting_frequency' => ['required', 'string', Rule::in(['quarterly', 'semiannual', 'monthly'])],
            'sss_rate_primary' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'sss_rate_secondary' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'sss_sector_primary' => ['nullable', 'string', 'max:120'],
            'sss_sector_secondary' => ['nullable', 'string', 'max:120'],
            'rounding_mode' => ['nullable', 'string', Rule::in(['chf_005', 'standard_001'])],
            'auto_detect_bezugsteuer' => ['nullable', 'boolean'],
            'bezugsteuer_threshold_chf' => ['nullable', 'numeric', 'min:0'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'canton' => ['nullable', 'string', 'max:4'],
            'gl_accounts' => ['nullable', 'array'],
        ];
    }
}
