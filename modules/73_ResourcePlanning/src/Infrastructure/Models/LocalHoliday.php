<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A holiday of a region or municipality (a patron saint, a cantonal feast) outside the national calendars. A row without a
 * tenant is generic data shared by every company; a row without a year recurs every year.
 *
 * @property string $country
 * @property string|null $region_code
 * @property string|null $municipality
 * @property int $month
 * @property int $day
 * @property int|null $year
 * @property string $name
 * @property numeric-string $day_fraction
 */
final class LocalHoliday extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'rp_local_holidays';

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer', 'year' => 'integer', 'day_fraction' => 'decimal:2'];
    }
}
