<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailReturnLine;

/**
 * A return of goods with its refund split and the exchange sale, if any.
 *
 * @mixin RetailReturn
 */
final class RetailReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'pos_sale_id' => $this->pos_sale_id,
            'pos_session_id' => $this->pos_session_id,
            'exchange_sale_id' => $this->exchange_sale_id,
            'commercial_document_id' => $this->commercial_document_id,
            'reason' => $this->reason,
            'refund_method' => $this->refund_method,
            'total_net' => $this->total_net,
            'total_tax' => $this->total_tax,
            'total_gross' => $this->total_gross,
            'credit_applied' => $this->credit_applied,
            'cash_refunded' => $this->cash_refunded,
            'other_refunded' => $this->other_refunded,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn (RetailReturnLine $line): array => [
                'id' => $line->id,
                'pos_sale_item_id' => $line->pos_sale_item_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'tax_rate' => $line->tax_rate,
                'net_amount' => $line->net_amount,
                'tax_amount' => $line->tax_amount,
                'gross_amount' => $line->gross_amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
