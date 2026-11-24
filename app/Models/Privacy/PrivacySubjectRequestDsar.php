<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use App\Enums\Privacy\DsarRequestType;
use App\Enums\Privacy\DsarStatus;
use App\Enums\Privacy\DsarSubjectType;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Data subject access request of the Privacy Governance suite (GDPR art. 12/15/17/20, nLPD art. 25/28).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_number
 * @property DsarSubjectType $subject_type
 * @property DsarRequestType $request_type
 * @property DsarStatus $status
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $statutory_deadline_at
 */
final class PrivacySubjectRequestDsar extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** Statutory term: art. 12(3) GDPR, art. 25(7) nLPD. */
    public const int DeadlineDays = 30;

    /** Maximum extension on top of the statutory term (art. 12(3) GDPR). */
    public const int MaxExtensionDays = 60;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected $table = 'privacy_dsar_requests';

    protected $appends = ['days_remaining', 'is_overdue'];

    protected function casts(): array
    {
        return [
            'subject_type' => DsarSubjectType::class,
            'request_type' => DsarRequestType::class,
            'status' => DsarStatus::class,
            'received_at' => 'immutable_datetime',
            'statutory_deadline_at' => 'immutable_datetime',
            'identity_verified_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'deadline_extended' => 'boolean',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [DsarStatus::Completed, DsarStatus::PartiallyRefusedFiscalRetention, DsarStatus::Rejected], true);
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if ($this->isClosed()) {
            return null;
        }

        return (int) floor(CarbonImmutable::now()->diffInDays($this->statutory_deadline_at, false));
    }

    public function getIsOverdueAttribute(): bool
    {
        return ! $this->isClosed() && $this->statutory_deadline_at->isPast();
    }
}
