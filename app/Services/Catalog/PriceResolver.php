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
     * Within a list the item with the highest min_quantity not above the requested quantity wins.
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
            $item = PriceListItem::where('price_list_id', $list->id)
                ->where('product_id', $product->id)
                ->where('min_quantity', '<=', $quantity)
                ->orderByDesc('min_quantity')
                ->first();

            if ($item !== null) {
                return ['price' => (float) $item->price, 'source' => 'price_list', 'price_list_id' => $list->id];
            }
        }

        return ['price' => (float) $product->sale_price, 'source' => 'product', 'price_list_id' => null];
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
