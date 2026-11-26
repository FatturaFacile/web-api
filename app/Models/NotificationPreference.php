<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Per-user, per-tenant scheduled ERP daily report preferences.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $channel
 * @property string $category
 * @property bool $enabled
 * @property string $frequency
 * @property string|null $custom_cron
 * @property string $send_time
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property string $language
 * @property bool $include_dashboard
 * @property bool $include_billing
 * @property bool $include_deadlines
 * @property bool $include_crm
 * @property bool $include_calendar
 * @property bool $include_inventory
 * @property bool $include_support
 * @property bool $include_news
 * @property bool $include_insights
 * @property Carbon|null $last_sent_at
 * @property string|null $last_period_key
 * @property Carbon|null $unsubscribed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const CHANNEL_ERP_REPORT = 'erp_report';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_CUSTOM_CRON = 'custom_cron';

    /**
     * @var list<string>
     */
    public const FREQUENCIES = [
        self::FREQUENCY_DAILY,
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_CUSTOM_CRON,
    ];

    /**
     * @var list<string>
     */
    public const SUPPORTED_LANGUAGES = [
        'it-CH',
        'de-CH',
        'fr-CH',
        'en',
        'it-IT',
    ];

    /**
     * @var list<string>
     */
    public const MODULES = [
        'dashboard',
        'billing',
        'deadlines',
        'crm',
        'calendar',
        'inventory',
        'support',
        'news',
        'insights',
    ];

    protected $table = 'notification_preferences';

    protected $attributes = [
        'channel' => self::CHANNEL_ERP_REPORT,
        'enabled' => true,
        'frequency' => self::FREQUENCY_DAILY,
        'send_time' => '07:00',
        'language' => 'it-CH',
        'include_dashboard' => true,
        'include_billing' => true,
        'include_deadlines' => true,
        'include_crm' => true,
        'include_calendar' => true,
        'include_inventory' => true,
        'include_support' => true,
        'include_news' => true,
        'include_insights' => true,
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'channel',
        'category',
        'enabled',
        'frequency',
        'custom_cron',
        'send_time',
        'day_of_week',
        'day_of_month',
        'language',
        'include_dashboard',
        'include_billing',
        'include_deadlines',
        'include_crm',
        'include_calendar',
        'include_inventory',
        'include_support',
        'include_news',
        'include_insights',
        'last_sent_at',
        'last_period_key',
        'unsubscribed_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('erp_report_channel', static function (Builder $builder): void {
            $builder->where($builder->qualifyColumn('channel'), self::CHANNEL_ERP_REPORT);
        });

        static::saving(static function (self $preference): void {
            $preference->channel = self::CHANNEL_ERP_REPORT;
            $preference->category = 'daily_summary:'.(string) $preference->tenant_id;
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'include_dashboard' => 'boolean',
            'include_billing' => 'boolean',
            'include_deadlines' => 'boolean',
            'include_crm' => 'boolean',
            'include_calendar' => 'boolean',
            'include_inventory' => 'boolean',
            'include_support' => 'boolean',
            'include_news' => 'boolean',
            'include_insights' => 'boolean',
            'last_sent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
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
     * @return HasMany<ScheduledReportDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ScheduledReportDelivery::class, 'notification_preference_id');
    }

    /**
     * @return list<string>
     */
    public function selectedModules(): array
    {
        $selected = [];

        foreach (self::MODULES as $module) {
            if ((bool) $this->getAttribute("include_{$module}")) {
                $selected[] = $module;
            }
        }

        return $selected;
    }
}
