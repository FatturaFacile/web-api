<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Request of a data subject (access, portability, rectification, erasure, restriction, objection).
 *
 * Workflow: received, identity_verified, in_progress, then completed or rejected. Requests closed by the former
 * one-step erasure keep their status partially_completed_legal_hold.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_type
 * @property string $subject_type
 * @property string $subject_id
 * @property string $status
 * @property CarbonImmutable|null $deadline_at
 * @property CarbonImmutable|null $extended_until
 */
final class PrivacyDataSubjectRequest extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const array RequestTypes = ['access', 'portability', 'rectification', 'erasure', 'restriction', 'objection'];

    public const array ClosedStatuses = ['completed', 'rejected', 'partially_completed_legal_hold'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_data_subject_requests';

    protected $appends = ['effective_deadline_at', 'is_overdue', 'days_remaining'];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'immutable_datetime',
            'extended_until' => 'immutable_datetime',
            'identity_verified_at' => 'immutable_datetime',
            'in_progress_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'legal_holds' => 'array',
            'erasure_summary' => 'array',
            'completed_at' => 'immutable_datetime',
            'deadline_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<PrivacyDsrEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PrivacyDsrEvent::class, 'request_id')->orderBy('created_at');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::ClosedStatuses, true);
    }

    /**
     * The deadline in force: the extended one when the request was extended.
     */
    public function effectiveDeadline(): CarbonImmutable
    {
        $extended = $this->extended_until;

        return $extended !== null && $extended->greaterThan($this->deadline_at) ? $extended : ($this->deadline_at ?? CarbonImmutable::now());
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function effectiveDeadlineAt(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->deadline_at === null ? null : $this->effectiveDeadline()->toIso8601String());
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->deadline_at !== null && ! $this->isClosed() && $this->effectiveDeadline()->isPast());
    }

    /**
     * Whole days left to the deadline in force (negative when overdue), null once the request is closed.
     *
     * @return Attribute<int|null, never>
     */
    protected function daysRemaining(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->deadline_at === null || $this->isClosed() ? null : (int) floor(CarbonImmutable::now()->diffInSeconds($this->effectiveDeadline(), false) / 86400));
    }
}
