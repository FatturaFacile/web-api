<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Booking limits of a kind of space (for example one desk per person per day). A null limit means no limit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $space_kind
 * @property int|null $max_bookings_per_user_per_day
 * @property int|null $max_minutes_per_booking
 * @property int|null $max_advance_days
 * @property int $checkin_grace_minutes
 * @property bool $requires_checkin
 */
final class FacilityBookingRule extends BaseEnterpriseModel
{
    protected $table = 'fac_booking_rules';

    protected function casts(): array
    {
        return [
            'max_bookings_per_user_per_day' => 'integer',
            'max_minutes_per_booking' => 'integer',
            'max_advance_days' => 'integer',
            'checkin_grace_minutes' => 'integer',
            'requires_checkin' => 'boolean',
        ];
    }
}
