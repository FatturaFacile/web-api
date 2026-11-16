<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A mobile device of a user, identified by its FCM registration token (iOS devices are reached through FCM/APNs).
 *
 * @property string $id
 * @property string $user_id
 * @property string $platform
 * @property string $token
 * @property string|null $name
 */
final class NotificationDevice extends Model
{
    use HasUuids;

    protected $table = 'notification_devices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
