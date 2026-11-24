<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A notification held back for the daily digest (digest frequency, or silenced at the time).
 *
 * @property string $id
 * @property string $user_id
 * @property string $channel
 * @property string $category
 * @property string $title
 * @property string|null $body
 * @property string|null $deep_link
 */
final class NotificationDigestItem extends Model
{
    use HasUuids;

    protected $table = 'notification_digest_items';

    protected $guarded = [];
}
