<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An administrator rule that notifies when a business metric crosses a threshold (for example a customer whose
 * overdue invoices exceed 10,000 CHF). Recipients default to the tenant owners and administrators.
 *
 * @property string $id
 * @property string $name
 * @property string $metric
 * @property string $threshold
 * @property string|null $currency
 * @property string $category
 * @property list<string>|null $recipient_user_ids
 * @property bool $is_active
 */
final class NotificationAlertRule extends Model
{
    use BelongsToTenant, HasUuids;

    public const METRICS = ['customer_overdue_total'];

    protected $table = 'notification_alert_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['threshold' => 'decimal:2', 'recipient_user_ids' => 'array', 'is_active' => 'boolean'];
    }
}
