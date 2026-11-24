<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use App\Models\Contact;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\Pricing\Models\CustomerSpecialPrice;
use Modules\Pricing\Support\DiscountCascade;
use Modules\Pricing\Support\PriceRounder;

/**
 * Deterministic price resolution of sales lines. The base price comes from the first tier that has one:
 *
 * 1. a special price of the customer for the product (valid on the date, in the currency);
 * 2. the price list assigned to the customer;
 * 3. the price lists of the sales channel and currency, highest priority first;
 * 4. the default price list of the currency, then the catalogue price of the product.
 *
 * The cascade of the item (or special price) and the manual cascade of the line are then applied, the result is rounded
 * with the rounding mode of the price list, promotions are evaluated on the whole cart and the margin guardrail judges
 * every line. Rules are loaded once per resolution (the active price lists of the tenant), then looked up in memory.
 */
final class PriceResolutionEngine
{
    public const string TierCustomerSpecial = 'customer_special_price';

    public const string TierCustomerList = 'customer_price_list';

    public const string TierChannelList = 'channel_price_list';

    public const string TierBase = 'base_price';

    public const string TierDocumentList = 'document_price_list';

    public function __construct(
        private readonly PromotionEvaluator $promotions,
        private readonly MarginGuardrail $guardrail,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: numeric-string|int|float, manual_discount_cascade?: ?string}>  $lines
     * @return array{currency: string, channel: string, date: string, lines: list<array<string, mixed>>, gifts: list<array<string, mixed>>, applied_promotions: list<array<string, mixed>>, document_discount: string, subtotal: string, total: string, margin: array<string, mixed>}
     */
    public function resolve(?Contact $customer, string $currency, string $channel, CarbonInterface $date, array $lines, ?string $priceListId = null): array
    {
        $lists = $this->activeLists($currency, $date);
        $priced = [];

        foreach ($lines as $index => $line) {
            $priced[] = $this->priceLine($index, $line, $customer, $currency, $channel, $date, $lists, $priceListId);
        }

        $promotionResult = $this->promotions->evaluate(array_map(static fn (array $line): array => [
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
            'line_total' => $line['line_total'],
            'net_unit_price' => $line['net_unit_price'],
            'promotion_discount' => '0',
        ], $priced), $date);

        $subtotal = '0';
        $evaluations = collect();

        foreach ($priced as $i => $line) {
            $discount = (string) $promotionResult['lines'][$i]['promotion_discount'];
            $lineNet = bcsub($line['line_total'], $discount, 4);
            $effectiveUnit = bccomp($line['quantity'], '0', 4) > 0 ? bcdiv($lineNet, $line['quantity'], 4) : $line['net_unit_price'];
            $product = Product::query()->findOrFail($line['product_id']);
            $margin = $this->guardrail->evaluate($product, $effectiveUnit, $line['price_list_id']);

            $priced[$i] = [
                ...$line,
                'promotion_discount' => $discount,
                'line_net_total' => $lineNet,
                'effective_unit_price' => $effectiveUnit,
                'cost' => $margin['cost'],
                'margin_percent' => $margin['margin_percent'],
                'margin_status' => $margin['status'],
                'margin_threshold_percent' => $margin['threshold_percent'],
                'requires_approval_role' => $margin['requires_approval_role'],
            ];
            $evaluations->push($margin);
            $subtotal = bcadd($subtotal, $lineNet, 4);
        }

        $total = bcsub($subtotal, $promotionResult['document_discount'], 4);
        $summary = $this->guardrail->summarize($evaluations);

        return [
            'currency' => $currency,
            'channel' => $channel,
            'date' => $date->toDateString(),
            'lines' => $priced,
            'gifts' => $promotionResult['gifts'],
            'applied_promotions' => $promotionResult['applied'],
            'document_discount' => $promotionResult['document_discount'],
            'subtotal' => $subtotal,
            'total' => $total,
            'margin' => [
                'status' => $summary['status'],
                'margin_percent' => $summary['margin_percent'],
                'threshold_percent' => $summary['threshold_percent'],
                'document_margin_percent' => $this->documentMargin($priced, $total),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, PriceList>  $lists
     * @return array<string, mixed>
     */
    private function priceLine(int $index, array $line, ?Contact $customer, string $currency, string $channel, CarbonInterface $date, Collection $lists, ?string $priceListId = null): array
    {
        $product = Product::query()->findOrFail($line['product_id']);
        $quantity = bcadd((string) $line['quantity'], '0', 4);
        $trace = [];

        [$base, $tier, $list, $item, $special] = $this->basePrice($product, $customer, $currency, $channel, $date, $quantity, $lists, $trace, $priceListId);

        $cascade = DiscountCascade::parse($special->discount_cascade ?? $item?->discount_cascade, (string) ($special->discount_mode ?? $item->discount_mode ?? DiscountCascade::Multiplicative));
        $afterList = $cascade->apply($base);

        if (! $cascade->isEmpty()) {
            $trace[] = ['rule' => 'discount_cascade', 'notation' => $cascade->notation(), 'mode' => $cascade->mode, 'effective_percent' => $cascade->effectivePercent(), 'price' => $afterList['price']];
        }

        $manual = DiscountCascade::parse($line['manual_discount_cascade'] ?? null);
        $afterManual = $manual->apply($afterList['price']);

        if (! $manual->isEmpty()) {
            $trace[] = ['rule' => 'manual_discount_cascade', 'notation' => $manual->notation(), 'mode' => $manual->mode, 'effective_percent' => $manual->effectivePercent(), 'price' => $afterManual['price']];
        }

        $roundingMode = $list->rounding_mode ?? $this->channelRounding($lists, $channel);
        $net = PriceRounder::round($afterManual['price'], $roundingMode);
        $delta = bcsub($net, $afterManual['price'], 4);

        if ($roundingMode !== PriceRounder::None) {
            $trace[] = ['rule' => 'rounding', 'mode' => $roundingMode, 'delta' => $delta, 'price' => $net];
        }

        $discountPercent = bccomp($base, '0', 4) > 0 ? bcmul(bcsub('1', bcdiv($net, $base, 8), 8), '100', 2) : '0.00';

        return [
            'index' => $index,
            'product_id' => (string) $product->id,
            'quantity' => $quantity,
            'base_price' => bcadd($base, '0', 4),
            'price_source' => $tier,
            'price_list_id' => $list === null ? null : (string) $list->id,
            'discount_cascade' => $cascade->notation(),
            'discount_mode' => $cascade->mode,
            'manual_discount_cascade' => $manual->notation(),
            'discount_percentage' => $discountPercent,
            'rounding_mode' => $roundingMode,
            'rounding_delta' => $delta,
            'net_unit_price' => $net,
            'line_total' => bcmul($net, $quantity, 4),
            'trace' => $trace,
        ];
    }

    /**
     * @param  Collection<int, PriceList>  $lists
     * @param  list<array<string, mixed>>  $trace
     * @return array{0: string, 1: string, 2: ?PriceList, 3: ?PriceListItem, 4: ?CustomerSpecialPrice}
     */
    private function basePrice(Product $product, ?Contact $customer, string $currency, string $channel, CarbonInterface $date, string $quantity, Collection $lists, array &$trace, ?string $priceListId = null): array
    {
        $special = $customer === null ? null : $this->specialPrice($customer, $product, $currency, $date);

        if ($special !== null && $special->fixed_price !== null) {
            $trace[] = ['rule' => self::TierCustomerSpecial, 'special_price_id' => (string) $special->id, 'price' => bcadd((string) $special->fixed_price, '0', 4)];

            return [bcadd((string) $special->fixed_price, '0', 4), self::TierCustomerSpecial, null, null, $special];
        }

        $candidates = [];

        if ($priceListId !== null) {
            $candidates[] = [self::TierDocumentList, $lists->firstWhere('id', $priceListId)];
        }

        if ($customer?->price_list_id !== null) {
            $candidates[] = [self::TierCustomerList, $lists->firstWhere('id', $customer->price_list_id)];
        }

        foreach ($lists->where('channel', $channel)->sortByDesc('priority') as $list) {
            $candidates[] = [self::TierChannelList, $list];
        }

        foreach ($lists->where('is_default', true)->sortByDesc('priority') as $list) {
            $candidates[] = [self::TierBase, $list];
        }

        foreach ($candidates as [$tier, $list]) {
            if ($list === null) {
                continue;
            }

            $found = $this->priceInList($list, $lists, (string) $product->id, $quantity, $date);

            if ($found !== null) {
                $trace[] = ['rule' => $tier, 'price_list_id' => (string) $list->id, 'price_list_item_id' => (string) $found[1]->id, 'price' => $found[0]];

                return [$found[0], $tier, $list, $found[1], $special];
            }
        }

        $trace[] = ['rule' => self::TierBase, 'source' => 'product', 'price' => bcadd((string) $product->sale_price, '0', 4)];

        return [bcadd((string) $product->sale_price, '0', 4), self::TierBase, null, null, $special];
    }

    private function specialPrice(Contact $customer, Product $product, string $currency, CarbonInterface $date): ?CustomerSpecialPrice
    {
        $day = $date->toDateString();

        return CustomerSpecialPrice::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('currency', $currency)
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->orderByRaw('valid_from is null')
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * The item of the list for the quantity band (or of its parent, moved by the percentage of each child).
     *
     * @param  Collection<int, PriceList>  $lists
     * @return array{0: string, 1: PriceListItem}|null
     */
    private function priceInList(PriceList $list, Collection $lists, string $productId, string $quantity, CarbonInterface $date): ?array
    {
        $day = $date->toDateString();
        $adjustments = [];
        $seen = [];
        $current = $list;

        while ($current !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;

            $item = PriceListItem::query()
                ->where('price_list_id', $current->id)
                ->where('product_id', $productId)
                ->where('min_quantity', '<=', $quantity)
                ->where(static fn ($query) => $query->whereNull('max_quantity')->orWhere('max_quantity', '>=', $quantity))
                ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
                ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
                ->orderByDesc('min_quantity')
                ->orderByRaw('valid_from is null')
                ->orderByDesc('valid_from')
                ->first();

            if ($item !== null) {
                $price = array_reduce($adjustments, static fn (string $p, string $percent): string => bcdiv(bcmul($p, bcadd('100', $percent, 3), 7), '100', 4), (string) $item->price);

                return [bcadd($price, '0', 4), $item];
            }

            if ($current->parent_price_list_id === null) {
                return null;
            }

            array_unshift($adjustments, (string) ($current->price_adjustment_percent ?? '0'));
            $current = $lists->firstWhere('id', $current->parent_price_list_id);
        }

        return null;
    }

    /**
     * @param  Collection<int, PriceList>  $lists
     */
    private function channelRounding(Collection $lists, string $channel): string
    {
        return (string) ($lists->where('channel', $channel)->sortByDesc('priority')->first()->rounding_mode ?? PriceRounder::None);
    }

    /**
     * @return Collection<int, PriceList>
     */
    private function activeLists(string $currency, CarbonInterface $date): Collection
    {
        $day = $date->toDateString();

        return PriceList::query()
            ->where('is_active', true)
            ->where('currency', $currency)
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function documentMargin(array $lines, string $total): ?string
    {
        $cost = '0';

        foreach ($lines as $line) {
            $cost = bcadd($cost, bcmul((string) $line['cost'], (string) $line['quantity'], 4), 4);
        }

        if (bccomp($total, '0', 4) <= 0) {
            return null;
        }

        return bcmul(bcdiv(bcsub($total, $cost, 4), $total, 8), '100', 2);
    }
}
