<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\AssetDisposal;

/**
 * A total or partial disposal of an asset with gain or loss. Amounts are decimal strings.
 *
 * @mixin AssetDisposal
 */
final class AssetDisposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'disposal_number' => $this->disposal_number,
            'disposal_type' => $this->disposal_type,
            'disposal_date' => $this->disposal_date?->format('Y-m-d'),
            'disposed_percentage' => $this->disposed_percentage !== null ? (string) $this->disposed_percentage : null,
            'is_partial' => (bool) $this->is_partial,
            'asset_cost_at_disposal' => $this->asset_cost_at_disposal !== null ? (string) $this->asset_cost_at_disposal : null,
            'accumulated_depreciation_at_disposal' => $this->accumulated_depreciation_at_disposal !== null ? (string) $this->accumulated_depreciation_at_disposal : null,
            'carrying_amount_at_disposal' => $this->carrying_amount_at_disposal !== null ? (string) $this->carrying_amount_at_disposal : null,
            'proceeds_amount' => $this->proceeds_amount !== null ? (string) $this->proceeds_amount : null,
            'disposal_costs' => $this->disposal_costs !== null ? (string) $this->disposal_costs : null,
            'gain_loss_amount' => $this->gain_loss_amount !== null ? (string) $this->gain_loss_amount : null,
            'fiscal_carrying_amount_at_disposal' => $this->fiscal_carrying_amount_at_disposal !== null ? (string) $this->fiscal_carrying_amount_at_disposal : null,
            'fiscal_gain_loss_amount' => $this->fiscal_gain_loss_amount !== null ? (string) $this->fiscal_gain_loss_amount : null,
            'fiscal_gain_spread_years' => $this->fiscal_gain_spread_years !== null ? (int) $this->fiscal_gain_spread_years : null,
            'buyer_contact_id' => $this->buyer_contact_id,
            'sales_invoice_id' => $this->sales_invoice_id,
            'disposal_document_number' => $this->disposal_document_number,
            'status' => $this->status,
            'journal_entry_id' => $this->journal_entry_id,
            'is_locked' => (bool) $this->is_locked,
            'reason' => $this->reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
