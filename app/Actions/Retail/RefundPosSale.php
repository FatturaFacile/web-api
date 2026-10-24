<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Actions\Loyalty\ApplyLoyaltyTransaction;
use App\Models\LoyaltyCard;
use App\Models\PosSale;
use App\Models\RtFiscalReceipt;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundPosSale
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ApplyLoyaltyTransaction $loyalty,
    ) {}

    /**
     * Refund a whole sale of a still-open session: stock returns, the session total drops and
     * loyalty movements are reversed. Sales already sent to the fiscal printer need a fiscal refund document.
     */
    public function execute(PosSale $sale): PosSale
    {
        return DB::transaction(function () use ($sale): PosSale {
            $sale = PosSale::with('session')->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status === 'refunded') {
                throw ValidationException::withMessages(['sale' => __('pos.already_refunded')]);
            }

            if (RtFiscalReceipt::where('pos_sale_id', $sale->id)->exists()) {
                throw ValidationException::withMessages(['sale' => __('pos.already_transmitted')]);
            }

            if ($sale->session->status !== 'open') {
                throw ValidationException::withMessages(['sale' => __('pos.refund_session_closed')]);
            }

            $this->stock->recordForLines('in', $sale, $sale->items()->get());
            $this->reverseLoyalty($sale);

            $sale->session->decrement('total_sales', (float) $sale->total_gross);
            $sale->update(['status' => 'refunded', 'refunded_at' => now()]);

            return $sale->load('items');
        });
    }

    private function reverseLoyalty(PosSale $sale): void
    {
        $card = $sale->loyalty_card_id !== null ? LoyaltyCard::find($sale->loyalty_card_id) : null;

        if ($card === null || $card->status !== 'active') {
            return;
        }

        $note = __('pos.refund_note', ['number' => $sale->receipt_number]);

        if ($sale->payment_method === 'prepaid') {
            $this->loyalty->execute($card, ['type' => 'topup', 'amount' => (float) $sale->total_gross, 'description' => $note]);

            return;
        }

        // Earned points can only be taken back up to the current balance.
        $reversible = min(floor((float) $sale->total_gross), (float) $card->balance);

        if ($card->type === 'loyalty' && $reversible > 0) {
            $this->loyalty->execute($card, ['type' => 'adjust', 'amount' => -$reversible, 'description' => $note]);
        }
    }
}
