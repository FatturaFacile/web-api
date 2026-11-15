<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Enums\OfferStatus;
use Modules\HumanResources\Enums\SignatureStatus;
use Modules\HumanResources\Models\Concerns\AuditsWithoutCandidatePii;

/**
 * Job offer letter sent to a candidate and its signature state.
 *
 * @property string $id
 * @property string $application_id
 * @property string $proposed_salary
 * @property string $currency
 * @property \Illuminate\Support\Carbon $start_date
 * @property string $employment_type
 * @property string $contract_type
 * @property string $weekly_hours
 * @property int $probation_months
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property OfferStatus $status
 * @property SignatureStatus $signature_status
 * @property string $signature_provider
 * @property string|null $signature_external_id
 * @property string|null $rendered_body
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $signed_at
 * @property \Illuminate\Support\Carbon|null $declined_at
 */
final class JobOffer extends BaseEnterpriseModel
{
    use AuditsWithoutCandidatePii;

    protected $table = 'rec_job_offers';

    protected function casts(): array
    {
        return [
            'proposed_salary' => 'decimal:2',
            'start_date' => 'date',
            'weekly_hours' => 'decimal:2',
            'probation_months' => 'integer',
            'valid_until' => 'date',
            'status' => OfferStatus::class,
            'signature_status' => SignatureStatus::class,
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class, 'application_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, OfferStatus::active(), true);
    }
}
