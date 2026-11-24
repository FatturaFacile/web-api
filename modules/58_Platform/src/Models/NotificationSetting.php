<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Do-not-disturb settings of a user: a daily quiet window (in the user's timezone, may cross midnight) and an
 * optional snooze until a given instant.
 *
 * @property string $id
 * @property string $user_id
 * @property bool $quiet_hours_enabled
 * @property string $quiet_start
 * @property string $quiet_end
 * @property string $timezone
 * @property Carbon|null $muted_until
 */
final class NotificationSetting extends Model
{
    use HasUuids;

    protected $table = 'notification_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quiet_hours_enabled' => 'boolean', 'muted_until' => 'datetime'];
    }
}
