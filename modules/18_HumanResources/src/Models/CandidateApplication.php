<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\CvParseStatus;
use Modules\HumanResources\Models\Concerns\AuditsWithoutCandidatePii;

/**
 * Candidate application to a job opening, shown as a card on the recruiting Kanban board.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reference
 * @property string $job_opening_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $cover_letter
 * @property string|null $notes
 * @property string $locale
 * @property string $source
 * @property ApplicationStage $stage
 * @property Carbon|null $stage_entered_at
 * @property int $board_position
 * @property string|null $cv_storage_key
 * @property string|null $cv_original_name
 * @property string|null $cv_mime_type
 * @property int|null $cv_size_bytes
 * @property CvParseStatus $cv_parse_status
 * @property array<string, mixed>|null $extracted_profile
 * @property list<string>|null $skills
 * @property Carbon|null $consent_given_at
 * @property string|null $consent_text_version
 * @property Carbon|null $privacy_consent_expires_at
 * @property Carbon|null $consent_extended_at
 * @property int $retention_months
 * @property string|null $rejection_reason_code
 * @property string|null $rejection_note
 * @property string|null $hired_employee_id
 * @property Carbon|null $anonymized_at
 */
final class CandidateApplication extends BaseEnterpriseModel
{
    use AuditsWithoutCandidatePii;

    public const string ANONYMIZED_FIRST_NAME = 'Anonymized';

    public const string ANONYMIZED_LAST_NAME = 'Candidate';

    protected $table = 'rec_candidate_applications';

    protected function casts(): array
    {
        return [
            'stage' => ApplicationStage::class,
            'stage_entered_at' => 'datetime',
            'board_position' => 'integer',
            'cv_size_bytes' => 'integer',
            'cv_parse_status' => CvParseStatus::class,
            'extracted_profile' => 'array',
            'skills' => 'array',
            'consent_given_at' => 'datetime',
            'privacy_consent_expires_at' => 'datetime',
            'consent_extended_at' => 'datetime',
            'retention_months' => 'integer',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobOpening, $this>
     */
    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }

    /**
     * @return HasMany<ApplicationStageHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(ApplicationStageHistory::class, 'application_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<InterviewScorecard, $this>
     */
    public function scorecards(): HasMany
    {
        return $this->hasMany(InterviewScorecard::class, 'application_id');
    }

    /**
     * @return HasMany<JobOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(JobOffer::class, 'application_id');
    }

    /**
     * The most recent offer letter of the application.
     *
     * @return HasOne<JobOffer, $this>
     */
    public function currentOffer(): HasOne
    {
        return $this->hasOne(JobOffer::class, 'application_id')->latestOfMany('created_at');
    }

    /**
     * @return HasMany<CandidateEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(CandidateEmail::class, 'application_id')->orderBy('created_at')->orderBy('id');
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function isHired(): bool
    {
        return $this->stage === ApplicationStage::Hired || $this->hired_employee_id !== null;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Erase the personal data of the candidate (GDPR art. 17 / nLPD art. 32) while keeping the statistical
     * attributes: stage, job opening, dates, scores and rejection reason code. The CV file itself is removed by
     * `CandidateRetentionService`, which is the only caller of this method.
     */
    public function anonymize(): void
    {
        $this->forceFill([
            'first_name' => self::ANONYMIZED_FIRST_NAME,
            'last_name' => self::ANONYMIZED_LAST_NAME,
            'email' => null,
            'phone' => null,
            'cover_letter' => null,
            'notes' => null,
            'cv_storage_key' => null,
            'cv_original_name' => null,
            'cv_mime_type' => null,
            'cv_size_bytes' => null,
            'extracted_profile' => null,
            'skills' => [],
            'rejection_note' => null,
            'anonymized_at' => now(),
        ])->save();

        $this->scorecards()->get()->each(static function (InterviewScorecard $scorecard): void {
            $scorecard->forceFill([
                'notes' => null,
                'criteria_scores' => array_map(
                    static fn (array $criterion): array => [...$criterion, 'comment' => null],
                    array_values(array_filter((array) $scorecard->criteria_scores, 'is_array'))
                ),
            ])->save();
        });

        ApplicationStageHistory::query()->where('application_id', $this->id)->update(['note' => null]);
        CandidateEmail::query()->where('application_id', $this->id)->update(['recipient_email' => null, 'subject' => null]);
        JobOffer::query()->where('application_id', $this->id)->update(['rendered_body' => null, 'decline_note' => null]);
    }
}
