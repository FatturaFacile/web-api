<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use App\Models\PriceListItem;
use Closure;
use Modules\Pricing\Models\CustomerSpecialPrice;
use Modules\Pricing\Models\PriceListHistory;

/**
 * Writes the immutable price history rows. The observers call it for every change of a price or a discount cascade; a
 * controller can name the reason of the change for the duration of a callback.
 */
final class PriceHistoryRecorder
{
    private ?string $reason = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withReason(?string $reason, Closure $callback): mixed
    {
        $previous = $this->reason;
        $this->reason = $reason;

        try {
            return $callback();
        } finally {
            $this->reason = $previous;
        }
    }

    public function recordItem(PriceListItem $item, string $event): void
    {
        $old = $event === 'created' ? null : $item->getOriginal();
        $new = $event === 'deleted' ? null : $item->getAttributes();

        if ($event === 'updated' && ! $item->wasChanged(['price', 'discount_cascade', 'discount_mode'])) {
            return;
        }

        $this->write((string) $item->tenant_id, [
            'price_list_id' => (string) $item->price_list_id,
            'price_list_item_id' => (string) $item->id,
            'product_id' => (string) $item->product_id,
        ], $old, $new, $event === 'deleted' ? $item->getAttributes() : null);
    }

    public function recordSpecialPrice(CustomerSpecialPrice $special, string $event): void
    {
        if ($event === 'updated' && ! $special->wasChanged(['fixed_price', 'discount_cascade', 'discount_mode'])) {
            return;
        }

        $old = $event === 'created' ? null : $special->getOriginal();
        $new = $event === 'deleted' ? null : $special->getAttributes();

        $this->write((string) $special->tenant_id, [
            'customer_special_price_id' => (string) $special->id,
            'product_id' => (string) $special->product_id,
        ], $old, $new, $event === 'deleted' ? $special->getAttributes() : null, 'fixed_price');
    }

    /**
     * @param  array<string, string>  $keys
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $deleted
     */
    private function write(string $tenantId, array $keys, ?array $old, ?array $new, ?array $deleted, string $priceKey = 'price'): void
    {
        $userId = auth()->id();

        PriceListHistory::query()->create([
            'tenant_id' => $tenantId !== '' ? $tenantId : null,
            ...$keys,
            'old_price' => $old[$priceKey] ?? null,
            'new_price' => $new[$priceKey] ?? null,
            'old_discount_cascade' => $old['discount_cascade'] ?? null,
            'new_discount_cascade' => $new['discount_cascade'] ?? null,
            'effective_at' => now(),
            'changed_by_user_id' => $userId === null ? null : (string) $userId,
            'reason' => $this->reason ?? ($deleted !== null ? 'deleted' : null),
        ]);
    }
}
