<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ExpenseManagement\Models\ExpenseLine;

/**
 * @mixin ExpenseLine
 */
final class LineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mileage = $this->relationLoaded('mileageClaim') ? $this->mileageClaim : null;

        return [
            'id' => $this->id,
            'expense_report_id' => $this->expense_report_id,
            'expense_date' => $this->expense_date->toDateString(),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn (): array => ['id' => $this->category->id, 'code' => $this->category->code, 'name_key' => $this->category->nameKey()]),
            'merchant_name' => $this->merchant_name,
            'merchant_tax_id' => $this->merchant_tax_id,
            'description' => $this->description,
            'original_amount' => $this->original_amount,
            'original_currency' => $this->original_currency,
            'exchange_rate' => $this->exchange_rate,
            'exchange_rate_date' => $this->exchange_rate_date?->toDateString(),
            'amount_in_base_currency' => $this->amount_in_base_currency,
            'vat_rate' => $this->vat_rate,
            'vat_amount' => $this->vat_amount,
            'vat_recoverable_amount' => $this->vat_recoverable_amount,
            'payment_method' => $this->payment_method->value,
            'corporate_card_transaction_id' => $this->corporate_card_transaction_id,
            'is_traceable_payment' => $this->is_traceable_payment,
            'is_taxable_benefit' => $this->is_taxable_benefit,
            'is_reimbursable' => $this->is_reimbursable,
            'policy_violations' => $this->policy_violations ?? [],
            'project_id' => $this->project_id,
            'billable_to_customer' => $this->billable_to_customer,
            'receipt_id' => $this->receipt_id,
            'receipt_document_id' => $this->receipt_document_id,
            'receipt_sha256' => $this->receipt_sha256,
            'has_receipt' => $this->receipt_id !== null,
            'mileage' => $mileage === null ? null : [
                'id' => $mileage->id,
                'origin_address' => $mileage->origin_address,
                'destination_address' => $mileage->destination_address,
                'waypoint_addresses' => $mileage->waypoint_addresses ?? [],
                'distance_km' => $mileage->distance_km,
                'is_round_trip' => $mileage->is_round_trip,
                'vehicle_class_code' => $mileage->vehicle_class_code,
                'vehicle_make_model' => $mileage->vehicle_make_model,
                'rate_per_km' => $mileage->rate_per_km,
                'calculated_amount' => $mileage->calculated_amount,
                'calculation_source' => $mileage->calculation_source->value,
                'rate_effective_date' => $mileage->rate_effective_date?->toDateString(),
                'rate_period' => $mileage->rate_period,
            ],
        ];
    }
}
