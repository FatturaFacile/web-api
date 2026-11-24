<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AgentCommissions\Domain\Enums\EnasarcoMandate;
use Modules\AgentCommissions\Models\FiscalParameter;

final class SaveFiscalOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settle', FiscalParameter::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', Rule::in(self::codes())],
            'mandate_type' => ['nullable', Rule::enum(EnasarcoMandate::class)],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'value' => ['nullable', 'numeric'],
            'payload' => ['nullable', 'array'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return [
            'enasarco_agent_rate',
            'enasarco_company_rate',
            'enasarco_massimale',
            'enasarco_minimale',
            'firr_brackets',
            'withholding_rate',
            'withholding_base_with_organization',
            'withholding_base_without_organization',
            'vat_rate',
        ];
    }
}
