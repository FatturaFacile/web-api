<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use App\Models\Product;
use Carbon\CarbonInterface;
use Modules\Pricing\Models\Promotion;

/**
 * Evaluates the active promotions on priced lines. A non-stackable promotion applies only when nothing applied before it
 * and then stops the rest. Line rewards raise `promotion_discount` of the lines; the cart tier is a document discount.
 */
final class PromotionEvaluator
{
    /**
     * @param  list<array<string, mixed>>  $lines  each with product_id, quantity, line_total, net_unit_price, promotion_discount
     * @return array{lines: list<array<string, mixed>>, applied: list<array<string, mixed>>, gifts: list<array<string, mixed>>, document_discount: string}
     */
    public function evaluate(array $lines, CarbonInterface $date): array
    {
        $applied = [];
        $gifts = [];
        $documentDiscount = '0';

        $promotions = Promotion::query()->orderBy('created_at')->orderBy('code')->get()
            ->filter(static fn (Promotion $promotion): bool => $promotion->isValidOn($date));

        foreach ($promotions as $promotion) {
            if (! $promotion->is_stackable && $applied !== []) {
                continue;
            }

            $rules = $promotion->rules;
            $amount = '0';
            $gift = null;

            if ($promotion->type === Promotion::BuyXGetY) {
                $amount = $this->buyXGetY($lines, $rules);
            } elseif ($promotion->type === Promotion::BundleDiscount) {
                $amount = $this->bundle($lines, $rules);
            } elseif ($promotion->type === Promotion::FreeGift) {
                $gift = $this->freeGift($lines, $rules);
            } elseif ($promotion->type === Promotion::CartValueTier) {
                $total = '0';

                foreach ($lines as $line) {
                    $total = bcadd($total, bcsub((string) $line['line_total'], (string) $line['promotion_discount'], 4), 4);
                }

                $amount = $this->cartTier($total, $rules);
                $documentDiscount = bcadd($documentDiscount, $amount, 4);
            }

            if ($gift === null && bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            if ($gift !== null) {
                $gifts[] = $gift + ['promotion_code' => $promotion->code];
            } elseif ($promotion->type !== Promotion::CartValueTier) {
                $lines = $this->spread($lines, $promotion->type, $rules, $amount);
            }

            $applied[] = [
                'promotion_id' => (string) $promotion->id,
                'code' => $promotion->code,
                'type' => $promotion->type,
                'discount_amount' => bcadd($amount, '0', 4),
                'stackable' => $promotion->is_stackable,
            ];

            if (! $promotion->is_stackable) {
                break;
            }
        }

        return ['lines' => $lines, 'applied' => $applied, 'gifts' => $gifts, 'document_discount' => bcadd($documentDiscount, '0', 4)];
    }

    /**
     * 3x2: for every `buy_qty` units the customer pays `pay_qty`; the free units are valued at the net unit price.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $rules
     */
    private function buyXGetY(array $lines, array $rules): string
    {
        $buy = (int) ($rules['buy_qty'] ?? 0);
        $pay = (int) ($rules['pay_qty'] ?? 0);
        $total = '0';

        if ($buy < 2 || $pay < 1 || $pay >= $buy) {
            return $total;
        }

        foreach ($lines as $line) {
            if (($rules['product_id'] ?? null) !== null && $rules['product_id'] !== $line['product_id']) {
                continue;
            }

            $free = intdiv((int) floor((float) $line['quantity']), $buy) * ($buy - $pay);
            $total = bcadd($total, bcmul((string) $free, (string) $line['net_unit_price'], 4), 4);
        }

        return $total;
    }

    /**
     * Each complete bundle (`min_each` of every listed product) gets `discount_percent` off those units.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $rules
     */
    private function bundle(array $lines, array $rules): string
    {
        $products = array_values((array) ($rules['product_ids'] ?? []));
        $each = max(1, (int) ($rules['min_each'] ?? 1));
        $percent = (string) ($rules['discount_percent'] ?? '0');

        if ($products === []) {
            return '0';
        }

        $bundles = null;

        foreach ($products as $productId) {
            $quantity = 0.0;

            foreach ($lines as $line) {
                if ($line['product_id'] === $productId) {
                    $quantity += (float) $line['quantity'];
                }
            }

            $count = intdiv((int) floor($quantity), $each);
            $bundles = $bundles === null ? $count : min($bundles, $count);
        }

        if (! $bundles) {
            return '0';
        }

        $value = '0';

        foreach ($lines as $line) {
            if (in_array($line['product_id'], $products, true)) {
                $value = bcadd($value, bcmul((string) ($bundles * $each), (string) $line['net_unit_price'], 4), 4);
            }
        }

        return bcmul($value, bcdiv($percent, '100', 8), 4);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|null
     */
    private function freeGift(array $lines, array $rules): ?array
    {
        $giftProductId = $rules['gift_product_id'] ?? null;

        if ($giftProductId === null) {
            return null;
        }

        $triggerProduct = $rules['trigger_product_id'] ?? null;
        $minQuantity = (string) ($rules['trigger_min_quantity'] ?? '0');
        $minAmount = (string) ($rules['trigger_min_amount'] ?? '0');
        $quantity = '0';
        $amount = '0';

        foreach ($lines as $line) {
            if ($triggerProduct === null || $triggerProduct === $line['product_id']) {
                $quantity = bcadd($quantity, (string) $line['quantity'], 4);
                $amount = bcadd($amount, bcsub((string) $line['line_total'], (string) $line['promotion_discount'], 4), 4);
            }
        }

        if ($lines === [] || bccomp($quantity, $minQuantity, 4) < 0 || bccomp($amount, $minAmount, 4) < 0) {
            return null;
        }

        $product = Product::query()->find($giftProductId);

        if ($product === null) {
            return null;
        }

        $giftQuantity = (string) ($rules['gift_quantity'] ?? 1);
        $treatment = (string) ($rules['vat_treatment'] ?? Promotion::WithoutVatRecourse);
        $value = bcmul((string) $product->sale_price, $giftQuantity, 4);

        return [
            'product_id' => (string) $product->id,
            'quantity' => bcadd($giftQuantity, '0', 4),
            'unit_price' => '0.0000',
            'is_gift' => true,
            'gift_value' => $value,
            'vat_treatment' => $treatment,
            // With recourse (rivalsa, art. 18 DPR 633/72) the VAT of the gift is charged to the customer; without it the
            // VAT is a cost of the seller and the gift line carries no VAT to charge.
            'vat_recourse_amount' => $treatment === Promotion::WithVatRecourse ? bcdiv(bcmul($value, (string) $product->tax_rate, 6), '100', 4) : '0.0000',
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function cartTier(string $total, array $rules): string
    {
        $best = '0';

        foreach ((array) ($rules['tiers'] ?? []) as $tier) {
            if (bccomp($total, (string) ($tier['min_amount'] ?? '0'), 4) >= 0 && bccomp((string) $tier['discount_percent'], $best, 4) > 0) {
                $best = (string) $tier['discount_percent'];
            }
        }

        return bcmul($total, bcdiv($best, '100', 8), 4);
    }

    /**
     * Charge the promotion amount to the lines it concerns, in proportion to their value.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $rules
     * @return list<array<string, mixed>>
     */
    private function spread(array $lines, string $type, array $rules, string $amount): array
    {
        $targets = [];

        foreach ($lines as $i => $line) {
            $matches = $type === Promotion::BundleDiscount
                ? in_array($line['product_id'], (array) ($rules['product_ids'] ?? []), true)
                : ($rules['product_id'] ?? null) === null || $rules['product_id'] === $line['product_id'];

            if ($matches) {
                $targets[] = $i;
            }
        }

        $base = '0';

        foreach ($targets as $i) {
            $base = bcadd($base, (string) $lines[$i]['line_total'], 4);
        }

        foreach ($targets as $i) {
            $share = bccomp($base, '0', 4) > 0 ? bcmul($amount, bcdiv((string) $lines[$i]['line_total'], $base, 8), 4) : '0';
            $lines[$i]['promotion_discount'] = bcadd((string) $lines[$i]['promotion_discount'], $share, 4);
        }

        return $lines;
    }
}
