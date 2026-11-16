<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Contracts\CandidateDocumentStorageInterface;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Events\CandidateDataAnonymizedEvent;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\CandidateApplication;

/**
 * GDPR (IT/EU) and nLPD (CH) retention of candidate data: consent expiry, consent extension and anonymisation.
 * Both the daily purge command and the `anonymize` endpoint go through `anonymize()`.
 */
final readonly class CandidateRetentionService
{
    public const string REASON_RETENTION_EXPIRED = 'retention_expired';

    public const string REASON_ERASURE_REQUEST = 'erasure_request';

    public function __construct(
        private CandidateDocumentStorageInterface $documents,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function consentExpiry(Carbon $consentGivenAt, int $retentionMonths): Carbon
    {
        return $consentGivenAt->copy()->addMonthsNoOverflow($retentionMonths);
    }

    public function extendConsent(CandidateApplication $application, int $months): CandidateApplication
    {
        if ($application->isAnonymized()) {
            throw RecruitingRuleViolationException::candidateAnonymized();
        }

        if ($application->privacy_consent_expires_at !== null && $application->privacy_consent_expires_at->isPast()) {
            throw RecruitingRuleViolationException::consentExpired();
        }

        $now = now();
        $application->forceFill([
            'retention_months' => $months,
            'consent_extended_at' => $now,
            'privacy_consent_expires_at' => $this->consentExpiry($now, $months),
        ])->save();

        return $application;
    }

    /**
     * Applications of the active tenant whose consent expired and that were neither hired nor anonymised yet.
     *
     * @return Builder<CandidateApplication>
     */
    public function expiredApplicationsQuery(?Carbon $at = null): Builder
    {
        return CandidateApplication::query()
            ->whereNotNull('privacy_consent_expires_at')
            ->where('privacy_consent_expires_at', '<', $at ?? now())
            ->whereNull('anonymized_at')
            ->whereNull('hired_employee_id')
            ->where('stage', '!=', ApplicationStage::Hired->value);
    }

    /**
     * Anonymise one application (idempotent) and delete its CV file once the database change is committed.
     */
    public function anonymize(CandidateApplication $application, string $reason): CandidateApplication
    {
        if ($application->isAnonymized()) {
            return $application;
        }

        $storageKey = $application->cv_storage_key;

        DB::transaction(function () use ($application, $reason): void {
            $application->anonymize();

            $this->auditLedger->record(
                action: 'HR_CANDIDATE_ANONYMIZED',
                model: $application,
                payloadBefore: null,
                payloadAfter: [
                    'reason' => $reason,
                    'stage' => $application->stage->value,
                    'anonymized_at' => $application->anonymized_at?->toIso8601String(),
                ],
                moduleCode: 'MODULE_18_HR'
            );

            event(new CandidateDataAnonymizedEvent(
                applicationId: (string) $application->id,
                jobOpeningId: (string) $application->job_opening_id,
                reason: $reason,
                tenantId: (string) $application->tenant_id,
                companyId: (string) $application->tenant_id
            ));
        });

        if ($storageKey !== null && $storageKey !== '') {
            $this->documents->delete($storageKey);
        }

        return $application;
    }

    /**
     * Anonymise every expired application of the active tenant.
     *
     * @return array{candidates: int, anonymized: int}
     */
    public function purgeExpired(bool $dryRun = false): array
    {
        $query = $this->expiredApplicationsQuery();
        $candidates = (clone $query)->count();

        if ($dryRun || $candidates === 0) {
            return ['candidates' => $candidates, 'anonymized' => 0];
        }

        $anonymized = 0;
        $query->chunkById(100, function ($applications) use (&$anonymized): void {
            foreach ($applications as $application) {
                /** @var CandidateApplication $application */
                $this->anonymize($application, self::REASON_RETENTION_EXPIRED);
                $anonymized++;
            }
        });

        return ['candidates' => $candidates, 'anonymized' => $anonymized];
    }
}
