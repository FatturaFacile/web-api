<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierPriceList;
use Modules\Procurement\Support\Decimal;

/**
 * Supplier catalogue entries and their price lists: quantity breaks with a validity period, in the currency of the
 * supplier. The applicable price of an entry is the price of the highest quantity break not above the ordered quantity
 * among the prices valid on the date.
 */
final class SupplierPriceService
{
    /**
     * Adds a price (quantity break) to a catalogue entry; two prices of the same entry, break and currency cannot be
     * valid on the same day.
     *
     * @param  array{currency?: ?string, min_quantity?: numeric-string|float|int|null, unit_price: numeric-string|float|int, valid_from: string, valid_to?: ?string}  $data
     *
     * @throws ProcurementRuleViolation when the validity overlaps another price of the same break
     */
    public function addPrice(SupplierCatalog $catalog, array $data): SupplierPriceList
    {
        return DB::transaction(function () use ($catalog, $data): SupplierPriceList {
            SupplierCatalog::query()->whereKey($catalog->id)->lockForUpdate()->firstOrFail();

            $minQuantity = Decimal::of($data['min_quantity'] ?? '1', 4);
            $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));
            $validTo = $data['valid_to'] ?? null;

            $overlaps = SupplierPriceList::query()
                ->where('supplier_catalog_id', $catalog->id)
                ->where('currency', $currency)
                ->where('min_quantity', $minQuantity)
                ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $data['valid_from']))
                ->when($validTo !== null, fn ($query) => $query->where('valid_from', '<=', $validTo))
                ->exists();

            if ($overlaps) {
                throw new ProcurementRuleViolation('Another price of this catalogue entry for the same quantity break is valid in the same period.', 'valid_from');
            }

            return SupplierPriceList::create([
                'tenant_id' => $catalog->tenant_id,
                'supplier_catalog_id' => $catalog->id,
                'currency' => $currency,
                'min_quantity' => $minQuantity,
                'unit_price' => Decimal::of($data['unit_price'], 4),
                'valid_from' => $data['valid_from'],
                'valid_to' => $validTo,
            ]);
        });
    }

    /**
     * The applicable price of the entry for the quantity on the date, or null when no price is valid.
     */
    public function priceFor(SupplierCatalog $catalog, string $quantity, string $date, ?string $currency = null): ?SupplierPriceList
    {
        return SupplierPriceList::query()
            ->where('supplier_catalog_id', $catalog->id)
            ->when($currency !== null, fn ($query) => $query->where('currency', strtoupper($currency)))
            ->where('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity')
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * Offers of every active catalogue entry with the internal SKU, cheapest first: supplier, entry, price and lead time.
     *
     * @return Collection<int, array{supplier_id: string, supplier_catalog_id: string, unit_price: string, currency: string, lead_time_days: int, min_quantity: string}>
     */
    public function offersForSku(string $internalSku, string $quantity, string $date): Collection
    {
        return SupplierCatalog::query()
            ->where('internal_sku', $internalSku)
            ->where('is_active', true)
            ->get()
            ->map(function (SupplierCatalog $catalog) use ($quantity, $date): ?array {
                $price = $this->priceFor($catalog, $quantity, $date);

                return $price === null ? null : [
                    'supplier_id' => (string) $catalog->supplier_id,
                    'supplier_catalog_id' => (string) $catalog->id,
                    'unit_price' => (string) $price->unit_price,
                    'currency' => (string) $price->currency,
                    'lead_time_days' => (int) $catalog->lead_time_days,
                    'min_quantity' => (string) $catalog->minimum_order_quantity,
                ];
            })
            ->filter()
            ->sortBy(static fn (array $offer): float => (float) $offer['unit_price'])
            ->values();
    }
}
