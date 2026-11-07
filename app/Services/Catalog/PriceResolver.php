<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Contact;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class PriceResolver
{
    /**
     * Resolve the unit price: contact price list, then tenant default list, then product sale price.
     * Within a list the item with the highest min_quantity not above the requested quantity wins. A list without the
     * product falls back on its parent, its price moved by the percentage of the child.
     *
     * @return array{price: float, source: string, price_list_id: ?string}
     */
    public function resolve(Product $product, ?Contact $contact, float $quantity = 1.0, ?CarbonInterface $date = null): array
    {
        $date ??= now();
        $candidates = [];

        if ($contact?->price_list_id !== null) {
            $candidates[] = $this->activeList($date)->whereKey($contact->price_list_id)->first();
        }

        $candidates[] = $this->activeList($date)->where('is_default', true)->first();

        foreach (array_filter($candidates) as $list) {
            $price = $this->priceInList($list, (string) $product->id, $quantity, $date);

            if ($price !== null) {
                return ['price' => (float) $price, 'source' => 'price_list', 'price_list_id' => $list->id];
            }
        }

        return ['price' => (float) $product->sale_price, 'source' => 'product', 'price_list_id' => null];
    }

    /**
     * The price in a list given by id, provided the list is active on that date.
     */
    public function priceInActiveList(string $listId, string $productId, float $quantity, CarbonInterface $date): ?string
    {
        $list = $this->activeList($date)->whereKey($listId)->first();

        return $list === null ? null : $this->priceInList($list, $productId, $quantity, $date);
    }

    /**
     * The price of a product in a list or, failing that, up its chain of parents.
     */
    public function priceInList(PriceList $list, string $productId, float $quantity, CarbonInterface $date): ?string
    {
        $adjustments = [];
        $seen = [];
        $current = $list;

        while ($current !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $item = $this->itemOn($current, $productId, $quantity, $date);

            if ($item !== null) {
                return array_reduce($adjustments, static fn (string $price, string $percent): string => bcdiv(bcmul($price, bcadd('100', $percent, 3), 7), '100', 4), (string) $item->price);
            }

            if ($current->parent_price_list_id === null) {
                return null;
            }

            array_unshift($adjustments, (string) ($current->price_adjustment_percent ?? '0'));
            $current = $this->activeList($date)->whereKey($current->parent_price_list_id)->first();
        }

        return null;
    }

    /**
     * Whether making `$parentId` the parent of `$listId` would close a loop.
     */
    public function wouldLoop(string $listId, string $parentId): bool
    {
        $seen = [];
        $current = $parentId;

        while ($current !== null && ! isset($seen[$current])) {
            if ($current === $listId) {
                return true;
            }

            $seen[$current] = true;
            $current = PriceList::query()->whereKey($current)->value('parent_price_list_id');
        }

        return false;
    }

    private function itemOn(PriceList $list, string $productId, float $quantity, CarbonInterface $date): ?PriceListItem
    {
        return PriceListItem::where('price_list_id', $list->id)
            ->where('product_id', $productId)
            ->where('min_quantity', '<=', $quantity)
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('min_quantity')
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * @return Builder<PriceList>
     */
    private function activeList(CarbonInterface $date)
    {
        return PriceList::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
