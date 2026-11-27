<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Application\Services\PassageTracker;
use Modules\GreenCare\Http\Support\ContactName;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * @mixin SeasonalMaintenanceContract
 */
final class ContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'site_id' => $this->site_id,
            'site_name' => $this->site->name ?? null,
            'customer_id' => $this->customer_id,
            'customer_name' => ContactName::of($this->customer),
            'condominium_administrator_id' => $this->condominium_administrator_id,
            'condominium_administrator_name' => ContactName::of($this->condominiumAdministrator),
            'season_start_date' => $this->season_start_date->format('Y-m-d'),
            'season_end_date' => $this->season_end_date->format('Y-m-d'),
            'billing_mode' => $this->billing_mode->value,
            'included_passages_count' => $this->included_passages_count,
            'completed_passages_count' => $this->completed_passages_count,
            'extra_passages_count' => $this->extraPassagesCount(),
            'extra_passage_unit_price' => (float) $this->extra_passage_unit_price,
            'fixed_fee_amount' => (float) $this->fixed_fee_amount,
            'installment_frequency' => $this->installment_frequency->value,
            'installments_billed' => $this->installments_billed,
            'time_and_material_hourly_rate' => (float) $this->time_and_material_hourly_rate,
            'snow_removal_standby_fee' => $this->snow_removal_standby_fee === null ? null : (float) $this->snow_removal_standby_fee,
            'snow_trigger_threshold_cm' => $this->snow_trigger_threshold_cm === null ? null : (float) $this->snow_trigger_threshold_cm,
            'included_services' => $this->included_services ?? [],
            'auto_bill_extra_passages' => $this->auto_bill_extra_passages,
            'apply_condominium_withholding' => $this->apply_condominium_withholding,
            'is_condominium' => $this->is_condominium,
            'condominium_fiscal_code' => $this->condominium_fiscal_code,
            'vat_rate' => (float) $this->vat_rate,
            'jurisdiction' => $this->jurisdiction,
            'status' => $this->status->value,
            'passage_counter' => app(PassageTracker::class)->counter($this->resource),
        ];
    }
}
