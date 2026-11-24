<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Associations\Enums\ApplicationStatus;

/**
 * What an applicant submitted. The payload is encrypted; the applicant follows it through a tracking code of which only
 * the hash is stored.
 */
final class EnrolmentApplication extends AssociationRecord
{
    protected $table = 'assoc_enrolment_applications';

    protected $hidden = ['tracking_hash', 'submitted_ip'];

    protected function casts(): array
    {
        return ['status' => ApplicationStatus::class, 'payload' => 'encrypted:array', 'decided_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(EnrolmentForm::class, 'form_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(EnrolmentUpload::class, 'application_id');
    }
}
