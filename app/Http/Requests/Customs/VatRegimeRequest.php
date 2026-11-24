<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use Illuminate\Foundation\Http\FormRequest;

class VatRegimeRequest extends FormRequest
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
            'tenant_country' => ['required', 'string', 'size:2'],
            'counterparty_country' => ['required', 'string', 'size:2'],
            'counterparty_vat_taxable' => ['sometimes', 'boolean'],
            'is_purchase' => ['sometimes', 'boolean'],
        ];
    }
}
