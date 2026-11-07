<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price list of positions: the own list of the company, personal and multilingual, or a catalogue imported under the
 * licence of its publisher (CRB NPK, ASIP/SMGV price data). The module ships no catalogue data.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $source
 * @property list<string> $languages
 */
final class PriceList extends TradesRecord
{
    /** @var list<string> */
    public const array Sources = ['own', 'npk', 'crb', 'asip', 'other'];

    /** @var list<string> */
    public const array Languages = ['de', 'fr', 'it', 'en'];

    protected $table = 'ct_price_lists';

    protected function casts(): array
    {
        return ['languages' => 'array', 'valid_from' => 'date', 'valid_to' => 'date', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<PriceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceItem::class, 'price_list_id')->orderBy('position_code');
    }
}
