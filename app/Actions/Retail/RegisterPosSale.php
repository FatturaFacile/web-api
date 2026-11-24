<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Actions\Loyalty\ApplyLoyaltyTransaction;
use App\Models\LoyaltyCard;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Product;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterPosSale
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly StockService $stock,
        private readonly ApplyLoyaltyTransaction $loyalty,
    ) {}

    /**
     * Register a sale in an open session. Totals are always computed server-side; line price and VAT
     * default to the product master data. Stock is unloaded and loyalty points are earned or redeemed.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(PosSession $session, array $data): PosSale
    {
        return DB::transaction(function () use ($session, $data): PosSale {
            $session = PosSession::lockForUpdate()->findOrFail($session->id);

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['pos_session_id' => __('pos.session_closed')]);
            }

            $computed = $this->totals->calculate($this->normalizeItems($data['items']));
            $gross = $computed['total_gross'];
            [$cash, $card] = $this->splitPayment($data, $gross);

            $loyaltyCard = isset($data['loyalty_card_id']) ? LoyaltyCard::findOrFail($data['loyalty_card_id']) : null;

            if ($data['payment_method'] === 'prepaid' && ($loyaltyCard === null || ! in_array($loyaltyCard->type, ['voucher', 'prepaid'], true))) {
                throw ValidationException::withMessages(['loyalty_card_id' => __('pos.prepaid_card_required')]);
            }

            $receipt = $this->numbering->next('pos_receipt');

            $sale = PosSale::create([
                'pos_session_id' => $session->id,
                'receipt_number' => $receipt['number'],
                'total_net' => $computed['total_net'],
                'total_tax' => $computed['total_tax'],
                'total_gross' => $gross,
                'payment_method' => $data['payment_method'],
                'contact_id' => $data['contact_id'] ?? $loyaltyCard?->contact_id,
                'loyalty_card_id' => $loyaltyCard?->id,
                'cash_amount' => $cash,
                'card_amount' => $card,
                'status' => 'completed',
            ]);

            $sale->items()->createMany($computed['items']);
            $this->stock->recordForLines('out', $sale, $sale->items()->get());

            $this->applyLoyalty($sale, $loyaltyCard, (string) $data['payment_method'], $gross);

            $session->increment('total_sales', $gross);

            return $sale->load('items');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $products = Product::whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');

        return array_map(function (array $item) use ($products): array {
            $product = isset($item['product_id']) ? $products->get($item['product_id']) : null;

            return array_merge($item, [
                'description' => $item['description'] ?? $product?->name,
                'unit_price' => $item['unit_price'] ?? $product?->sale_price ?? 0,
                'tax_rate' => $item['tax_rate'] ?? $product?->tax_rate ?? 0,
            ]);
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: float, 1: float} cash and card amounts
     */
    private function splitPayment(array $data, float $gross): array
    {
        switch ($data['payment_method']) {
            case 'cash':
                if (isset($data['cash_tendered']) && round((float) $data['cash_tendered'], 2) < $gross) {
                    throw ValidationException::withMessages(['cash_tendered' => __('pos.cash_tendered_too_low')]);
                }

                return [$gross, 0.0];
            case 'card':
                return [0.0, $gross];
            case 'mixed':
                $cash = round((float) ($data['cash_amount'] ?? 0), 2);

                if ($cash <= 0 || $cash >= $gross) {
                    throw ValidationException::withMessages(['cash_amount' => __('pos.mixed_split_invalid')]);
                }

                return [$cash, round($gross - $cash, 2)];
            default: // prepaid: paid from the card balance
                return [0.0, 0.0];
        }
    }

    private function applyLoyalty(PosSale $sale, ?LoyaltyCard $card, string $method, float $gross): void
    {
        if ($card === null) {
            return;
        }

        if ($method === 'prepaid') {
            $this->loyalty->execute($card, [
                'type' => 'redeem',
                'amount' => $gross,
                'description' => __('pos.redeem_note', ['number' => $sale->receipt_number]),
            ]);

            return;
        }

        $points = floor($gross);

        if ($card->type === 'loyalty' && $points > 0) {
            $this->loyalty->execute($card, [
                'type' => 'earn',
                'amount' => $points,
                'description' => __('pos.earn_note', ['number' => $sale->receipt_number]),
            ]);
        }
    }
}
