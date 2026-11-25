<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\VanSalesAndFieldForce\Models\VanEodSettlement;

/**
 * @mixin VanEodSettlement
 */
final class VanEodSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'van_warehouse_id' => $this->van_warehouse_id,
            'van_license_plate' => $this->whenLoaded('vanWarehouse', fn (): ?string => $this->vanWarehouse?->license_plate),
            'van_load_manifest_id' => $this->van_load_manifest_id,
            'field_route_plan_id' => $this->field_route_plan_id,
            'settlement_number' => $this->settlement_number,
            'settlement_date' => $this->settlement_date->toDateString(),
            'status' => $this->status->value,
            'stock_reconciliation_lines' => $this->stock_reconciliation_lines ?? [],
            'total_loaded_qty' => $this->total_loaded_qty,
            'total_sold_qty' => $this->total_sold_qty,
            'total_returned_damaged_qty' => $this->total_returned_damaged_qty,
            'total_freebies_qty' => $this->total_freebies_qty,
            'total_remaining_qty' => $this->total_remaining_qty,
            'total_keep_onboard_qty' => $this->total_keep_onboard_qty,
            'total_unload_central_qty' => $this->total_unload_central_qty,
            'empties_reconciliation' => $this->empties_reconciliation ?? [],
            'cold_chain_summary' => $this->cold_chain_summary ?? [],
            'opening_cash_float' => $this->opening_cash_float,
            'collected_cash_amount' => $this->collected_cash_amount,
            'collected_pos_card_amount' => $this->collected_pos_card_amount,
            'collected_twint_amount' => $this->collected_twint_amount,
            'collected_cheque_amount' => $this->collected_cheque_amount,
            'collected_bank_transfer_amount' => $this->collected_bank_transfer_amount,
            'expected_cash_remittance' => $this->expected_cash_remittance,
            'counted_cash_remittance' => $this->counted_cash_remittance,
            'cash_discrepancy_amount' => $this->cash_discrepancy_amount,
            'total_remittance_amount' => $this->total_remittance_amount,
            'deposit_slip_number' => $this->deposit_slip_number,
            'treasury_deposited' => $this->treasury_deposited,
            'central_warehouse_unloaded' => $this->central_warehouse_unloaded,
            'currency' => $this->currency,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
