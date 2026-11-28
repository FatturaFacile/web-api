<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Per-company settings of the school: the slug of the public booking portal, the jurisdiction (IT / CH) and the default retention.
 *
 * @property string $public_slug
 * @property bool $booking_enabled
 * @property string $jurisdiction
 * @property string $default_retention_mode
 * @property string $default_retention_value
 * @property string|null $meeting_point
 */
final class SchoolSetting extends BaseEnterpriseModel
{
    protected $table = 'ski_school_settings';

    protected function casts(): array
    {
        return [
            'booking_enabled' => 'boolean',
            'default_retention_value' => 'decimal:4',
        ];
    }
}
