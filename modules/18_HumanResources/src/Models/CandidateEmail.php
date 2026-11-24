<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Enums\EmailStatus;
use Modules\HumanResources\Models\Concerns\AuditsWithoutCandidatePii;

/**
 * Log entry of an email sent (or skipped) to a candidate on a stage change.
 *
 * @property string $id
 * @property string $application_id
 * @property string $stage
 * @property string|null $locale
 * @property string|null $recipient_email
 * @property string|null $subject
 * @property EmailStatus $status
 * @property string|null $failure_reason
 * @property Carbon|null $sent_at
 */
final class CandidateEmail extends BaseEnterpriseModel
{
    use AuditsWithoutCandidatePii;

    protected $table = 'rec_candidate_emails';

    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class, 'application_id');
    }
}
