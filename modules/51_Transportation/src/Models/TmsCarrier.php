<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A freight carrier with the tariff tables used to rate shipments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $mode
 * @property string|null $service_level
 * @property string $currency
 * @property bool $is_active
 * @property string|null $notes
 */
final class TmsCarrier extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Modes = ['road', 'air', 'sea', 'rail', 'courier'];

    protected $table = 'tms_carriers';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }


    /**
     * @return HasMany<TmsTariffTable, $this>
     */
    public function tariffTables(): HasMany
    {
        return $this->hasMany(TmsTariffTable::class, 'carrier_id');
    }
}
