<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to deliver a notification on one channel: queued (digest), sent, opened, failed or skipped.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $notification_id
 * @property string $category
 * @property string $channel
 * @property string|null $title
 * @property string|null $deep_link
 * @property string $status
 * @property string|null $error
 */
final class NotificationDelivery extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const OPENED = 'opened';

    public const FAILED = 'failed';

    protected $table = 'notification_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime'];
    }
}
