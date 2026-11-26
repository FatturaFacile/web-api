<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ScheduledReportDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tracks each scheduled or on-demand ERP daily report delivery, open/click metrics, and errors.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string|null $notification_preference_id
 * @property string $recipient_email
 * @property string $frequency
 * @property string $period_key
 * @property string $language
 * @property bool $is_test
 * @property string $status
 * @property string|null $message_id
 * @property string|null $provider_status
 * @property string|null $error_message
 * @property string|null $error_trace
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $clicked_at
 * @property int $open_count
 * @property int $click_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScheduledReportDelivery extends Model
{
    /** @use HasFactory<ScheduledReportDeliveryFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_OPENED = 'opened';

    public const STATUS_CLICKED = 'clicked';

    public const STATUS_FAILED = 'failed';

    public const STATUS_GENERATION_FAILED = 'generation_failed';

    protected $table = 'scheduled_report_deliveries';

    protected $attributes = [
        'frequency' => NotificationPreference::FREQUENCY_DAILY,
        'language' => 'it-CH',
        'is_test' => false,
        'status' => self::STATUS_QUEUED,
        'open_count' => 0,
        'click_count' => 0,
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'notification_preference_id',
        'recipient_email',
        'frequency',
        'period_key',
        'language',
        'is_test',
        'status',
        'message_id',
        'provider_status',
        'error_message',
        'error_trace',
        'queued_at',
        'sent_at',
        'opened_at',
        'clicked_at',
        'open_count',
        'click_count',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'open_count' => 'integer',
            'click_count' => 'integer',
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
     * @return BelongsTo<NotificationPreference, $this>
     */
    public function preference(): BelongsTo
    {
        return $this->belongsTo(NotificationPreference::class, 'notification_preference_id');
    }

    /**
     * @return HasMany<ScheduledReportAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ScheduledReportAuditLog::class, 'delivery_id');
    }
}
