<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AccountsPayable\Enums\OpenItemKind;

/**
 * A manual supplier open item (a document that did not come as an electronic or QR invoice).
 */
final class StoreOpenItemRequest extends FormRequest
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
            'supplier_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'kind' => ['nullable', Rule::enum(OpenItemKind::class)],
            'document_number' => ['required', 'string', 'max:100'],
            'document_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:document_date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'creditor_iban' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z]{2}\d{2}[A-Za-z0-9 ]{10,30}$/'],
            'skonto_percentage' => ['nullable', 'numeric', 'gt:0', 'max:100', 'required_with:skonto_days'],
            'skonto_days' => ['nullable', 'integer', 'min:0', 'max:365', 'required_with:skonto_percentage'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
