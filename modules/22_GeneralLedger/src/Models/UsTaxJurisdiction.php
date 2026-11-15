<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A United States sales tax jurisdiction (state, county, city or special district) with its dated rates.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $state
 * @property string $level
 * @property string|null $county
 * @property string|null $city
 * @property string|null $zip_prefix
 * @property bool $is_active
 */
final class UsTaxJurisdiction extends BaseEnterpriseModel
{
    public const array Levels = ['state', 'county', 'city', 'special'];

    protected $table = 'us_tax_jurisdictions';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<UsTaxRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(UsTaxRate::class, 'jurisdiction_id');
    }
}
