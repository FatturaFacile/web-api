<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry of the register of personal data breaches (GDPR art. 33(5), nLPD art. 24). The 72 hour notification deadline
 * runs from the moment the company became aware of the breach (discovered_at).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property CarbonImmutable $discovered_at
 * @property CarbonImmutable|null $notification_deadline_at
 * @property CarbonImmutable|null $fdpic_notified_at
 * @property CarbonImmutable|null $gdpr_authority_notified_at
 * @property bool $requires_authority_notification
 */
final class PrivacyDataBreach extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const array Statuses = ['investigating', 'contained', 'notified', 'closed'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_data_breaches';

    protected $appends = ['hours_remaining', 'is_notification_overdue'];

    protected function casts(): array
    {
        return [
            'discovered_at' => 'immutable_datetime',
            'notification_deadline_at' => 'immutable_datetime',
            'fdpic_notified_at' => 'immutable_datetime',
            'gdpr_authority_notified_at' => 'immutable_datetime',
            'subjects_notified_at' => 'immutable_datetime',
            'contained_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'affected_records_count' => 'integer',
            'affected_subjects_count' => 'integer',
            'data_categories' => 'array',
            'requires_authority_notification' => 'boolean',
            'requires_subject_notification' => 'boolean',
        ];
    }

    public function authorityNotifiedAt(): ?CarbonImmutable
    {
        return $this->gdpr_authority_notified_at ?? $this->fdpic_notified_at;
    }

    /**
     * True when the authority notification is required and still missing.
     */
    public function awaitsAuthorityNotification(): bool
    {
        return $this->requires_authority_notification && $this->authorityNotifiedAt() === null;
    }

    /**
     * Hours left to the 72 hour deadline (negative when passed), null when no authority notification is pending.
     *
     * @return Attribute<float|null, never>
     */
    protected function hoursRemaining(): Attribute
    {
        return Attribute::get(function (): ?float {
            if ($this->notification_deadline_at === null || ! $this->awaitsAuthorityNotification()) {
                return null;
            }

            return round(CarbonImmutable::now()->diffInMinutes($this->notification_deadline_at, false) / 60, 1);
        });
    }

    /**
     * True when the authority was not notified within 72 hours: either still pending after the deadline, or notified late.
     *
     * @return Attribute<bool, never>
     */
    protected function isNotificationOverdue(): Attribute
    {
        return Attribute::get(function (): bool {
            if ($this->notification_deadline_at === null || ! $this->requires_authority_notification) {
                return false;
            }

            $notified = $this->authorityNotifiedAt();

            return $notified === null ? $this->notification_deadline_at->isPast() : $notified->greaterThan($this->notification_deadline_at);
        });
    }
}
