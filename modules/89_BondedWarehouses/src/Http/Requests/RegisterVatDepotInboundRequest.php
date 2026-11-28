<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;

final class RegisterVatDepotInboundRequest extends FormRequest
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
            'authorization_id' => ['required', 'uuid', Rule::exists('customs_warehouse_authorizations', 'id')->where('tenant_id', $tenantId)],
            'movement_type' => ['required', Rule::in([VatDepotMovementType::InboundFreeCirculation->value, VatDepotMovementType::InboundIntraEU->value, VatDepotMovementType::InternalTransferNoVAT->value])],
            'movement_date' => ['required', 'date'],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'lot_reference' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'base_goods_value' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
