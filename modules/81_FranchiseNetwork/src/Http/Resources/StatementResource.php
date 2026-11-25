<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\RoyaltyStatement;

/**
 * @mixin RoyaltyStatement
 */
final class StatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'store_id' => $this->store_id,
            'store_code' => $this->store?->code,
            'store_name' => $this->store?->name,
            'period' => $this->period,
            'status' => $this->status->value,
            'skip_reason' => $this->skip_reason,
            'net_sales' => $this->net_sales,
            'royalty_gross' => $this->royalty_gross,
            'startup_factor' => $this->startup_factor,
            'royalty_after_startup' => $this->royalty_after_startup,
            'minimum_guarantee_applied' => $this->minimum_guarantee_applied,
            'minimum_adjustment' => $this->minimum_adjustment,
            'royalty_charged' => $this->royalty_charged,
            'marketing_fund' => $this->marketing_fund,
            'loyalty_settlement' => $this->loyalty_settlement,
            'total_net' => $this->total_net,
            'breakdown' => $this->breakdown,
            'invoice_id' => $this->invoice_id,
        ];
    }
}
