<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\IndustrialLaundry\Models\BillingRun;
use Modules\IndustrialLaundry\Models\BillingRunLine;
use Modules\IndustrialLaundry\Support\ContactName;

/**
 * The monthly wash and rental billing of a contract with its lines and the invoice it drafted.
 *
 * @mixin BillingRun
 */
final class BillingRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'contract_code' => $this->relationLoaded('contract') ? $this->contract?->code : null,
            'customer_id' => $this->customer_id,
            'customer' => $this->relationLoaded('customer') ? ContactName::summary($this->customer) : null,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status->value,
            'billing_model' => $this->billing_model->value,
            'jurisdiction' => $this->jurisdiction->value,
            'currency' => $this->currency,
            'washed_pieces' => $this->washed_pieces,
            'net_weight_kg' => (string) $this->net_weight_kg,
            'wash_amount' => (string) $this->wash_amount,
            'minimum_top_up_amount' => (string) $this->minimum_top_up_amount,
            'rental_amount' => (string) $this->rental_amount,
            'unreturned_amount' => (string) $this->unreturned_amount,
            'subtotal' => (string) $this->subtotal,
            'tax_rate' => (string) $this->tax_rate,
            'tax_amount' => (string) $this->tax_amount,
            'total' => (string) $this->total,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice_number,
            'compliance_report' => $this->compliance_report,
            'lines' => $this->relationLoaded('lines') ? $this->lines->map(static fn (BillingRunLine $line): array => [
                'id' => (string) $line->id,
                'position' => $line->position,
                'kind' => $line->kind->value,
                'article_type' => $line->article_type?->value,
                'quantity' => (string) $line->quantity,
                'uom' => $line->uom,
                'unit_price' => (string) $line->unit_price,
                'amount' => (string) $line->amount,
                'detail' => $line->detail,
            ])->values()->all() : null,
        ];
    }
}
