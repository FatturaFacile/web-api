<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ENASARCO rate, annual minimum contribution and annual commission ceiling for one year. Rows without a tenant are
 * shared defaults; a tenant row for the same year overrides them.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property int $year
 * @property string $rate
 * @property string $annual_minimum
 * @property string $annual_maximum_commission
 */
class EnasarcoAnnualLimit extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'year',
        'rate',
        'annual_minimum',
        'annual_maximum_commission',
    ];

    /**
     * Shared rows (tenant_id null) are visible to every tenant.
     */
    public function isSharedLookup(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'rate' => 'decimal:2',
            'annual_minimum' => 'decimal:2',
            'annual_maximum_commission' => 'decimal:2',
        ];
    }
}
