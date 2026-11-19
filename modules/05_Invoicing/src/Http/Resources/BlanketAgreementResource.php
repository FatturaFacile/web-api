<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\BlanketAgreement;
use Modules\Invoicing\Models\BlanketAgreementLine;

/**
 * @mixin BlanketAgreement
 */
final class BlanketAgreementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $committed = (string) $this->total_committed_amount;
        $calledOff = (string) $this->total_called_off_amount;

        return [
            'id' => $this->id,
            'agreement_number' => $this->agreement_number,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->whenLoaded('customer', fn (): ?string => $this->customer?->company_name),
            'currency_code' => $this->currency_code,
            'exchange_rate' => (string) $this->exchange_rate,
            'agreement_type' => $this->agreement_type,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to->toDateString(),
            'days_to_expiry' => (int) now()->startOfDay()->diffInDays($this->valid_to, false),
            'status' => $this->status,
            'total_committed_amount' => $committed,
            'total_called_off_amount' => $calledOff,
            'remaining_amount' => bcsub($committed, $calledOff, 4),
            'called_off_percentage' => bccomp($committed, '0', 4) > 0 ? bcmul(bcdiv($calledOff, $committed, 6), '100', 2) : '0.00',
            'pricing_lock_mode' => $this->pricing_lock_mode,
            'over_release_tolerance_percent' => (string) $this->over_release_tolerance_percent,
            'payment_terms' => $this->payment_terms,
            'incoterms' => $this->incoterms,
            'notes' => $this->notes,
            'renewed_from_id' => $this->renewed_from_id,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(static fn (BlanketAgreementLine $line): array => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'unit_of_measure' => $line->unit_of_measure,
                'target_quantity' => (string) $line->target_quantity,
                'called_off_quantity' => (string) $line->called_off_quantity,
                'delivered_quantity' => (string) $line->delivered_quantity,
                'remaining_quantity' => $line->remaining_quantity,
                'unit_price' => (string) $line->unit_price,
                'discount_percent' => (string) $line->discount_percent,
                'tax_rate' => (string) $line->tax_rate,
                'target_amount' => (string) $line->target_amount,
                'called_off_amount' => (string) $line->called_off_amount,
                'delivery_schedule' => $line->delivery_schedule,
            ])->all()),
        ];
    }
}
