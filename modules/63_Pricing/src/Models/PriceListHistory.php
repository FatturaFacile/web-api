<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable audit row of a price or discount change: it can be created, never changed or deleted.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $price_list_id
 * @property string|null $price_list_item_id
 * @property string|null $old_price
 * @property string|null $new_price
 */
final class PriceListHistory extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'price_list_histories';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'effective_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw new LogicException('Price history rows are immutable.');
        };

        self::updating($refuse);
        self::deleting($refuse);
    }
}
