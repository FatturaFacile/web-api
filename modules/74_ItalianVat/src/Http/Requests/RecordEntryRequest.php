<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ItalianVat\Services\VatSectionService;

/**
 * Records a document in a sales or purchases register. Amounts are positive, the kind of the document gives the sign.
 */
final class RecordEntryRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'section_id' => ['required', 'uuid', Rule::exists('it_vat_sections', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'entry_date' => ['required', 'date'],
            'document_date' => ['nullable', 'date'],
            'document_number' => ['nullable', 'string', 'max:50'],
            'document_kind' => ['nullable', Rule::in(VatSectionService::DOCUMENT_KINDS)],
            'document_type_code' => ['nullable', 'string', 'max:4'],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'counterparty_name' => ['nullable', 'string', 'max:200'],
            'counterparty_vat' => ['nullable', 'string', 'max:30'],
            'taxable_amount' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'between:0,100'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_nature' => ['nullable', 'string', 'max:6'],
            'apply_pro_rata' => ['nullable', 'boolean'],
            'deduction_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'non_deductible_reason' => ['nullable', Rule::in(['pro_rata', 'art_19_bis_1', 'other'])],
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
