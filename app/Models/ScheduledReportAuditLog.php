<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ScheduledReportAuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit trail for every scheduled ERP report lifecycle event.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string|null $delivery_id
 * @property string $event_type
 * @property string|null $recipient_email
 * @property string|null $period_key
 * @property string|null $message_id
 * @property string|null $provider_status
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $error_trace
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 */
class ScheduledReportAuditLog extends Model
{
    /** @use HasFactory<ScheduledReportAuditLogFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const EVENT_PREFERENCE_CREATED = 'preference_created';

    public const EVENT_PREFERENCE_UPDATED = 'preference_updated';

    public const EVENT_TENANT_SETTINGS_UPDATED = 'tenant_settings_updated';

    public const EVENT_REPORT_QUEUED = 'report_queued';

    public const EVENT_REPORT_SENT = 'report_sent';

    public const EVENT_REPORT_FAILED = 'report_failed';

    public const EVENT_REPORT_GENERATION_FAILED = 'report_generation_failed';

    public const EVENT_EMAIL_OPENED = 'email_opened';

    public const EVENT_LINK_CLICKED = 'link_clicked';

    public const EVENT_UNSUBSCRIBED = 'unsubscribed';

    protected $table = 'scheduled_report_audit_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'delivery_id',
        'event_type',
        'recipient_email',
        'period_key',
        'message_id',
        'provider_status',
        'ip_address',
        'user_agent',
        'error_trace',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ScheduledReportDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ScheduledReportDelivery::class, 'delivery_id');
    }
}
