<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Enums\WarehouseSubtype;

/**
 * ADM (Italy / EU) or UDSC / BAZG (Switzerland) authorization of a customs warehouse, IVA warehouse (Art. 50-bis DL 331/93) or free zone,
 * with its comprehensive guarantee (CGU) ceiling and AEO status.
 *
 * @property string $warehouse_id
 * @property string $authorization_code
 * @property string $customs_office_code
 * @property RegimeType $regime_type
 * @property WarehouseSubtype $warehouse_subtype
 * @property string|null $cgu_guarantee_reference
 * @property string $cgu_max_ceiling_amount
 * @property string $cgu_utilized_amount
 * @property string $currency
 * @property string|null $aeo_certificate_number
 * @property AeoStatus $aeo_status
 * @property Carbon|null $aeo_next_audit_date
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 */
final class CustomsWarehouseAuthorization extends BaseEnterpriseModel
{
    protected $table = 'customs_warehouse_authorizations';

    protected function casts(): array
    {
        return [
            'regime_type' => RegimeType::class,
            'warehouse_subtype' => WarehouseSubtype::class,
            'aeo_status' => AeoStatus::class,
            'cgu_max_ceiling_amount' => 'decimal:2',
            'cgu_utilized_amount' => 'decimal:2',
            'aeo_next_audit_date' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function isUsableOn(Carbon $date): bool
    {
        return $this->is_active
            && $this->valid_from->copy()->startOfDay()->lte($date)
            && ($this->valid_to === null || $this->valid_to->copy()->endOfDay()->gte($date));
    }

    public function availablePlafond(): string
    {
        return number_format(max(0.0, (float) $this->cgu_max_ceiling_amount - (float) $this->cgu_utilized_amount), 2, '.', '');
    }

    public function utilizationPercent(): float
    {
        $ceiling = (float) $this->cgu_max_ceiling_amount;

        return $ceiling > 0 ? round((float) $this->cgu_utilized_amount / $ceiling * 100, 2) : 0.0;
    }

    /**
     * @return HasMany<BondedMrnLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(BondedMrnLot::class, 'authorization_id');
    }
}
