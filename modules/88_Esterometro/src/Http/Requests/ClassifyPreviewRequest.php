<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;

final class ClassifyPreviewRequest extends FormRequest
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
            'passive_invoice_id' => ['nullable', 'uuid'],
            'supplier_country_code' => ['required_without:passive_invoice_id', 'string', 'size:2'],
            'transaction_nature' => ['nullable', Rule::in(TransactionNature::values())],
            'source_vat_nature' => ['nullable', 'string', 'max:10'],
            'has_customs_import_bill' => ['nullable', 'boolean'],
            'supplier_charged_italian_vat' => ['nullable', 'boolean'],
            'regularization_trigger' => ['nullable', 'string', 'in:omitted_4_months,irregular_30_days,manual'],
            'override_document_type' => ['nullable', Rule::in(SelfInvoiceDocumentType::values())],
        ];
    }
}
