<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use App\Enums\Privacy\BreachIncidentStatus;
use App\Enums\Privacy\BreachJurisdiction;
use App\Enums\Privacy\BreachRisk;
use App\Enums\Privacy\BreachSeverity;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Data breach incident with the 72 hour notification clock (GDPR art. 33, nLPD art. 24).
 *
 * @property string $id
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable $notification_deadline_72h
 * @property BreachJurisdiction $jurisdiction
 * @property BreachIncidentStatus $status
 * @property BreachSeverity $severity
 * @property BreachRisk $risk_to_rights_and_freedoms
 * @property int $affected_subjects_count
 * @property list<string>|null $affected_data_categories
 * @property string $incident_code
 * @property string $tenant_id
 * @property CarbonImmutable|null $authority_notified_at
 */
final class DataBreachIncident extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected $table = 'privacy_data_breach_incidents';

    protected $appends = ['seconds_remaining'];

    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'notification_deadline_72h' => 'immutable_datetime',
            'authority_notified_at' => 'immutable_datetime',
            'subjects_notified_at' => 'immutable_datetime',
            'jurisdiction' => BreachJurisdiction::class,
            'severity' => BreachSeverity::class,
            'risk_to_rights_and_freedoms' => BreachRisk::class,
            'status' => BreachIncidentStatus::class,
            'affected_data_categories' => 'array',
            'affected_subjects_count' => 'integer',
        ];
    }

    /** True while the authority has not been notified (the clock keeps running). */
    public function isAwaitingNotification(): bool
    {
        return $this->authority_notified_at === null && $this->status !== BreachIncidentStatus::Closed;
    }

    /** Seconds to the 72 hour deadline (negative once overdue); null when already notified or closed. */
    public function getSecondsRemainingAttribute(): ?int
    {
        return $this->isAwaitingNotification()
            ? (int) CarbonImmutable::now()->diffInSeconds($this->notification_deadline_72h, false)
            : null;
    }
}
