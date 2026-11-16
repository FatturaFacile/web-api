<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFreeTradeAgreementRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:255', Rule::unique('free_trade_agreements', 'code')->ignore($this->route('id'))],
            'name' => [$presence, 'array'],
            'name.*' => ['nullable', 'string'],
            'partner_countries' => [$presence, 'array', 'min:2'],
            'partner_countries.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'value_threshold_eur' => ['nullable', 'numeric', 'min:0'],
            'value_threshold_chf' => ['nullable', 'numeric', 'min:0'],
            'proof_types' => [$presence, 'array'],
            'proof_types.*' => ['string', 'max:50'],
            'supports_approved_exporter' => ['sometimes', 'boolean'],
            'supports_rex' => ['sometimes', 'boolean'],
            'declaration_templates' => [$presence, 'array'],
            'declaration_templates.*' => ['nullable', 'string'],
            'declaration_templates_no_auth' => [$presence, 'array'],
            'declaration_templates_no_auth.*' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
