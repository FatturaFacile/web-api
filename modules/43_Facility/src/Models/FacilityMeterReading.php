<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A reading of a meter with the consumption since the previous reading and its cost.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $meter_id
 * @property Carbon $read_on
 * @property string $value
 * @property string $consumption
 * @property string|null $cost
 * @property string|null $currency
 * @property string $reading_type
 * @property string|null $recorded_by
 * @property string|null $notes
 */
final class FacilityMeterReading extends BaseEnterpriseModel
{
    public const string Regular = 'regular';

    public const string Rollover = 'rollover';

    public const string Replacement = 'replacement';

    /** @var list<string> */
    public const array Types = [self::Regular, self::Rollover, self::Replacement];

    protected $table = 'fac_meter_readings';

    protected function casts(): array
    {
        return [
            'read_on' => 'date',
            'value' => 'decimal:3',
            'consumption' => 'decimal:3',
            'cost' => 'decimal:2',
        ];
    }
}
