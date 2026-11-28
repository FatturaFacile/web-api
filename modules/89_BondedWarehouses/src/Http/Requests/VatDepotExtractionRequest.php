<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;

/**
 * Extraction from an IVA warehouse and its preview (same payload). The F24 reference and date belong to TD23 only.
 */
final class VatDepotExtractionRequest extends FormRequest
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
        $td23 = VatDepotMovementType::ExtractionTD23->value;

        return [
            'authorization_id' => ['required', 'uuid', Rule::exists('customs_warehouse_authorizations', 'id')->where('tenant_id', $tenantId)],
            'movement_type' => ['required', Rule::in([VatDepotMovementType::ExtractionTD22->value, $td23, VatDepotMovementType::ExtractionLetterOfIntent->value])],
            'movement_date' => ['required', 'date'],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'lot_reference' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'base_goods_value' => ['required', 'numeric', 'min:0'],
            'storage_and_processing_services_value' => ['sometimes', 'numeric', 'min:0'],
            'vat_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'f24_payment_reference' => ['required_if:movement_type,'.$td23, 'prohibited_unless:movement_type,'.$td23, 'nullable', 'string', 'max:60'],
            'f24_payment_date' => ['required_if:movement_type,'.$td23, 'prohibited_unless:movement_type,'.$td23, 'nullable', 'date'],
            'declaration_of_intent_id' => ['nullable', 'uuid'],
            'self_contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
