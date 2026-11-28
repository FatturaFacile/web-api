<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OliveMillAndGrainMill\Domain\Enums\ByproductType;

/**
 * Dispatch or use of a by-product.
 */
final class RecordByproductRequest extends FormRequest
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
            'byproduct_type' => ['required', Rule::enum(ByproductType::class)],
            'movement_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'delivery_id' => ['nullable', 'uuid', Rule::exists('mill_deliveries', 'id')->where('tenant_id', $tenantId)],
            'cycle_id' => ['nullable', 'uuid', Rule::exists('mill_milling_cycles', 'id')->where('tenant_id', $tenantId)],
            'counterparty_contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'counterparty_name' => ['nullable', 'string', 'max:160'],
            'destination_type' => ['nullable', Rule::in(['SANSIFICIO', 'BIOMASS', 'LAND_SPREADING', 'FEED', 'OTHER'])],
            'transport_document' => ['nullable', 'string', 'max:80'],
            'cadastral_parcel' => ['nullable', 'string', 'max:80'],
            'parcel_area_ha' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
