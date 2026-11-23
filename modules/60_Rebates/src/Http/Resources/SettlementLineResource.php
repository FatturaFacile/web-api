<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateSettlementLine;

/**
 * A row of the analytical breakdown of a settlement proposal.
 *
 * @mixin RebateSettlementLine
 */
final class SettlementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_type' => $this->line_type,
            'passive_invoice_id' => $this->passive_invoice_id,
            'reference' => $this->reference,
            'reference_date' => $this->reference_date?->toDateString(),
            'net_amount' => $this->net_amount,
            'rebate_amount' => $this->rebate_amount,
        ];
    }
}
