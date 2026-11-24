<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Resources;

use App\Models\PosSale;
use App\Models\PosSaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FiscalRetail\Models\RetailSalePayment;
use Modules\FiscalRetail\Models\RetailSalePromotion;

/**
 * A sales ticket: the POS sale with its lines (discount lines have a negative unit price), payments by method and the
 * promotions applied.
 *
 * @mixin PosSale
 */
final class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pos_session_id' => $this->pos_session_id,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'contact_id' => $this->contact_id,
            'total_net' => $this->total_net,
            'total_tax' => $this->total_tax,
            'total_gross' => $this->total_gross,
            'payment_method' => $this->payment_method,
            'items' => $this->items->map(static fn (PosSaleItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => (string) $item->tax_rate,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'payments' => RetailSalePayment::query()->where('pos_sale_id', $this->id)->orderBy('created_at')->get()->map(static fn (RetailSalePayment $payment): array => [
                'method' => $payment->method,
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ])->all(),
            'promotions' => RetailSalePromotion::query()->where('pos_sale_id', $this->id)->get()->map(static fn (RetailSalePromotion $promotion): array => [
                'code' => $promotion->code,
                'name' => $promotion->name,
                'discount_net' => $promotion->discount_net,
            ])->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
