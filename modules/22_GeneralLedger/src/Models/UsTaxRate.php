<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The sales tax rate of a jurisdiction for a period.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $jurisdiction_id
 * @property string $rate_percent
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $applies_to_shipping
 */
final class UsTaxRate extends BaseEnterpriseModel
{
    protected $table = 'us_tax_rates';

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'applies_to_shipping' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<UsTaxJurisdiction, $this>
     */
    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(UsTaxJurisdiction::class, 'jurisdiction_id');
    }
}
