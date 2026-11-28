<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A milk producer (allevatore / Milchproduzent) with its stable and registry identifiers (CH TVD / BDM, IT ASL / CUAA).
 *
 * @property string|null $contact_id
 * @property string $code
 * @property string $name
 * @property string $jurisdiction
 * @property string|null $farm_code
 * @property string|null $tvd_number
 * @property string|null $bdm_number
 * @property string|null $asl_code
 * @property string|null $cuaa
 * @property string|null $municipality
 * @property string|null $canton_or_province
 * @property bool $is_active
 * @property string|null $notes
 * @property-read Collection<int,MilkDelivery> $deliveries
 * @property-read Collection<int,QualityTest> $qualityTests
 */
final class Breeder extends BaseEnterpriseModel
{
    protected $table = 'dairy_breeders';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MilkDelivery::class, 'breeder_id');
    }

    public function qualityTests(): HasMany
    {
        return $this->hasMany(QualityTest::class, 'breeder_id');
    }
}
