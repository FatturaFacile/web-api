<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;

/**
 * @mixin MillingSettlement
 */
final class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'cycle_id' => $this->cycle_id,
            'tariff_mode' => $this->tariff_mode->value,
            'tariff_value' => $this->tariff_value,
            'service_net' => $this->service_net,
            'service_vat_rate' => $this->service_vat_rate,
            'service_vat' => $this->service_vat,
            'service_total' => $this->service_total,
            'settlement_mode' => $this->settlement_mode->value,
            'molenda_percent' => $this->molenda_percent,
            'retained_kg' => $this->retained_kg,
            'oil_price_per_kg' => $this->oil_price_per_kg,
            'retained_value_net' => $this->retained_value_net,
            'purchase_vat_rate' => $this->purchase_vat_rate,
            'purchase_vat' => $this->purchase_vat,
            'purchase_total' => $this->purchase_total,
            'cash_balance' => $this->cash_balance,
            'compensation_amount' => $this->compensation_amount,
            'tank_id' => $this->tank_id,
            'service_invoice_id' => $this->service_invoice_id,
            'purchase_doc_id' => $this->purchase_doc_id,
            'sian_movement_id' => $this->sian_movement_id,
            'status' => $this->status,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'lot_code' => $this->whenLoaded('delivery', fn (): string => $this->delivery->lot_code),
            'farmer_name' => $this->whenLoaded('delivery', fn (): ?string => $this->delivery->relationLoaded('farmer') ? $this->farmerName($this->delivery->farmer) : null),
        ];
    }

    private function farmerName(?Contact $farmer): ?string
    {
        if ($farmer === null) {
            return null;
        }

        $company = trim((string) $farmer->company_name);

        return $company !== '' ? $company : trim($farmer->first_name.' '.$farmer->last_name);
    }
}
