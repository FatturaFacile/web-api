<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\NaturaIva;
use Modules\SDI\Enums\TipoDocumento;

class StoreSelfInvoiceRequest extends FormRequest
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
            'document_type' => ['required', Rule::enum(TipoDocumento::class)],
            'date' => ['required', 'date'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'vat_nature' => ['nullable', Rule::in(NaturaIva::issuable()), 'required_if:tax_rate,0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
