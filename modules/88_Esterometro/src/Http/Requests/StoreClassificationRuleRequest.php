<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;

final class StoreClassificationRuleRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'document_type' => ['required', Rule::in(SelfInvoiceDocumentType::values())],
            'supplier_region' => ['required', 'string', 'in:IT,EU,EXTRA_EU,SM,ANY'],
            'supplier_country_code' => ['nullable', 'string', 'size:2'],
            'transaction_nature' => ['required', 'string', 'max:30'],
            'source_vat_nature_prefix' => ['nullable', 'string', 'max:10'],
            'default_vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'default_vat_nature' => ['nullable', 'string', 'max:10'],
            'default_deductibility_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'soggetto_emittente' => ['nullable', 'string', 'in:CC,TZ'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'legal_reference' => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
