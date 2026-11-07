<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A position of a price list, with descriptions per language and its cost components.
 *
 * @property string $id
 * @property string $price_list_id
 * @property string $position_code
 * @property string $unit
 * @property string $unit_price
 * @property ?string $labour_minutes
 * @property ?string $material_cost
 * @property ?string $machine_cost
 * @property array<string, string> $descriptions
 */
final class PriceItem extends TradesRecord
{
    protected $table = 'ct_price_items';

    protected function casts(): array
    {
        return ['descriptions' => 'array', 'unit_price' => 'decimal:4', 'labour_minutes' => 'decimal:2', 'material_cost' => 'decimal:4', 'machine_cost' => 'decimal:4'];
    }

    /**
     * The description in the language, or the first one available.
     */
    public function describe(string $language): string
    {
        $descriptions = $this->descriptions;

        return (string) ($descriptions[$language] ?? (reset($descriptions) ?: $this->position_code));
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }
}
