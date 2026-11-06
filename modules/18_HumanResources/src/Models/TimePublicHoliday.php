<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A public holiday added by hand, or a half day (art. 20a LL), on top of the cantonal calendar: a row with a null
 * tenant is generic data, visible to every company.
 */
final class TimePublicHoliday extends BaseEnterpriseModel
{
    protected $table = 'time_public_holidays';

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'day_fraction' => 'decimal:2',
        ];
    }

    /**
     * The tenant scope also returns the generic rows.
     */
    public function isSharedLookup(): bool
    {
        return true;
    }
}
