<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A price list of a carrier valid for a period. The rate basis says whether the price follows weight, distance, both or is flat; bands hold the prices.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $carrier_id
 * @property string $name
 * @property string $currency
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $rate_basis
 * @property string $fuel_surcharge_percent
 * @property string $minimum_charge
 * @property bool $is_active
 * @property string|null $notes
 */
final class TmsTariffTable extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array RateBases = ['flat', 'weight', 'distance', 'weight_distance'];

    protected $table = 'tms_tariff_tables';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'fuel_surcharge_percent' => 'decimal:2',
            'minimum_charge' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TmsCarrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TmsCarrier::class, 'carrier_id');
    }

    /**
     * @return HasMany<TmsTariffBand, $this>
     */
    public function bands(): HasMany
    {
        return $this->hasMany(TmsTariffBand::class, 'tariff_table_id');
    }
}
