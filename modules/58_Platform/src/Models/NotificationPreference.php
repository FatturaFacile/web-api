<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a user receives the notifications of a category on a channel. A missing row means enabled.
 *
 * @property string $id
 * @property string $user_id
 * @property string $channel
 * @property string $category
 * @property bool $enabled
 */
final class NotificationPreference extends Model
{
    use HasUuids;

    protected $table = 'notification_preferences';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
