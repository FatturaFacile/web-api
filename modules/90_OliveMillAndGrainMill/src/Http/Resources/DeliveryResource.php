<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * @mixin OliveDelivery
 */
final class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_year' => $this->campaign_year,
            'lot_code' => $this->lot_code,
            'delivered_at' => $this->delivered_at->toIso8601String(),
            'processing_mode' => $this->processing_mode->value,
            'commodity' => $this->commodity->value,
            'grain_type' => $this->grain_type,
            'farmer_contact_id' => $this->farmer_contact_id,
            'cultivar' => $this->cultivar,
            'origin_municipality' => $this->origin_municipality,
            'cadastral_reference' => $this->cadastral_reference,
            'sian_origin_code' => $this->sian_origin_code,
            'certification' => $this->certification->value,
            'gross_weight_kg' => $this->gross_weight_kg,
            'tare_kg' => $this->tare_kg,
            'crate_count' => $this->crate_count,
            'crate_unit_tare_kg' => $this->crate_unit_tare_kg,
            'net_weight_kg' => $this->net_weight_kg,
            'weighbridge_ticket' => $this->weighbridge_ticket,
            'scale_payload' => $this->scale_payload,
            'scale_read_at' => $this->scale_read_at?->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'hopper_line' => $this->hopper_line,
            'status' => $this->status->value,
            'sms_reminder_sent_at' => $this->sms_reminder_sent_at?->toIso8601String(),
            'sms_recipient_phone' => $this->sms_recipient_phone,
            'sms_status' => $this->sms_status,
            'notes' => $this->notes,
            'farmer_name' => $this->whenLoaded('farmer', fn (): ?string => $this->farmerName($this->farmer)),
            'cycle' => $this->whenLoaded('cycle', fn (): ?array => $this->cycle === null ? null : [
                'id' => $this->cycle->id,
                'status' => $this->cycle->status->value,
                'yield_percent' => $this->cycle->yield_percent,
                'output_kg' => $this->cycle->output_kg,
            ]),
            'settlement_id' => $this->whenLoaded('settlement', fn (): ?string => $this->settlement?->id),
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
