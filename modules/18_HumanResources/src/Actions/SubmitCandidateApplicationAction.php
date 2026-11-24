<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\CandidateDocumentStorageInterface;
use Modules\HumanResources\Contracts\CandidateEmailSenderInterface;
use Modules\HumanResources\Contracts\CvParserInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\DTOs\ParsedCvDTO;
use Modules\HumanResources\DTOs\StoredCandidateDocumentDTO;
use Modules\HumanResources\DTOs\SubmitCandidateApplicationDTO;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\CvParseStatus;
use Modules\HumanResources\Events\CandidateApplicationReceivedEvent;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\ApplicationStageHistory;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Services\CandidateRetentionService;
use RuntimeException;
use Throwable;

/**
 * `SubmitCandidateApplicationAndParseCvCommand`: stores the CV privately, parses it (a parsing failure never
 * blocks the application), records the privacy consent and puts the candidate in the `applied` column.
 */
final readonly class SubmitCandidateApplicationAction
{
    public function __construct(
        private CandidateDocumentStorageInterface $documents,
        private CvParserInterface $cvParser,
        private RecruitmentServiceInterface $recruitment,
        private CandidateEmailSenderInterface $emailSender,
        private CandidateRetentionService $retention,
        private TenantContext $tenantContext
    ) {}

    public function execute(SubmitCandidateApplicationDTO $dto): CandidateApplication
    {
        /** @var JobOpening $opening */
        $opening = JobOpening::query()->findOrFail($dto->jobOpeningId);

        if (! $opening->status->isOpen()) {
            throw RecruitingRuleViolationException::openingNotOpen();
        }

        $this->assertNotDuplicate($opening, $dto->email);

        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $opening->tenant_id);
        $stored = $dto->cv !== null ? $this->storeCv($dto->cv, $tenantId) : null;
        $parsed = $stored !== null ? $this->parseCv($dto->cv, $stored) : null;

        try {
            $application = DB::transaction(function () use ($dto, $opening, $stored, $parsed): CandidateApplication {
                JobOpening::query()->lockForUpdate()->findOrFail($opening->id);
                $this->assertNotDuplicate($opening, $dto->email);

                $now = now();
                $application = new CandidateApplication([
                    'reference' => $this->nextReference(),
                    'job_opening_id' => $opening->id,
                    'first_name' => trim($dto->firstName),
                    'last_name' => trim($dto->lastName),
                    'email' => strtolower(trim($dto->email)),
                    'phone' => $dto->phone ?? $parsed?->phone,
                    'cover_letter' => $dto->coverLetter,
                    'locale' => $dto->locale->value,
                    'source' => $dto->source->value,
                    'stage' => ApplicationStage::Applied,
                    'stage_entered_at' => $now,
                    'board_position' => $this->recruitment->nextBoardPosition((string) $opening->id, ApplicationStage::Applied),
                    'cv_storage_key' => $stored?->storageKey,
                    'cv_original_name' => $stored?->originalName,
                    'cv_mime_type' => $stored?->mimeType,
                    'cv_size_bytes' => $stored?->sizeBytes,
                    'cv_parse_status' => $parsed !== null ? $parsed->status : CvParseStatus::NotProvided,
                    'extracted_profile' => $parsed?->profile,
                    'skills' => $parsed !== null ? $parsed->skills : [],
                    'consent_given_at' => $now,
                    'consent_text_version' => $dto->consentTextVersion,
                    'retention_months' => $dto->retentionMonths,
                    'privacy_consent_expires_at' => $this->retention->consentExpiry($now, $dto->retentionMonths),
                ]);
                $application->save();

                ApplicationStageHistory::create([
                    'application_id' => $application->id,
                    'from_stage' => null,
                    'to_stage' => ApplicationStage::Applied->value,
                    'actor_user_id' => $dto->actorUserId !== null && Str::isUuid($dto->actorUserId) ? $dto->actorUserId : null,
                ]);

                event(new CandidateApplicationReceivedEvent(
                    applicationId: (string) $application->id,
                    jobOpeningId: (string) $opening->id,
                    source: $dto->source->value,
                    cvParseStatus: $application->cv_parse_status->value,
                    tenantId: (string) $application->tenant_id,
                    companyId: (string) $application->tenant_id
                ));

                return $application;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $this->documents->delete($stored->storageKey);
            }

            throw $exception;
        }

        if ($this->recruitment->settings()->stageAutoEmail(ApplicationStage::Applied)) {
            $this->emailSender->sendStageEmail($application, ApplicationStage::Applied);
        }

        return $application;
    }

    /**
     * Re-run the CV parser on the stored CV of an application.
     */
    public function reparse(CandidateApplication $application): CandidateApplication
    {
        if ($application->isAnonymized()) {
            throw RecruitingRuleViolationException::candidateAnonymized();
        }

        if ($application->cv_storage_key === null) {
            $application->forceFill(['cv_parse_status' => CvParseStatus::NotProvided])->save();

            return $application;
        }

        $contents = $this->documents->contents($application->cv_storage_key);
        $parsed = $contents === null
            ? ParsedCvDTO::failed()
            : $this->safeParse($contents, (string) $application->cv_mime_type, (string) $application->cv_original_name);

        $application->forceFill([
            'cv_parse_status' => $parsed->status,
            'extracted_profile' => $parsed->profile,
            'skills' => $parsed->skills,
        ])->save();

        return $application;
    }

    private function assertNotDuplicate(JobOpening $opening, string $email): void
    {
        $exists = CandidateApplication::query()
            ->where('job_opening_id', $opening->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->where('stage', '!=', ApplicationStage::Rejected->value)
            ->exists();

        if ($exists) {
            throw RecruitingRuleViolationException::duplicateApplication();
        }
    }

    private function storeCv(UploadedFile $cv, string $tenantId): StoredCandidateDocumentDTO
    {
        try {
            return $this->documents->store($cv, $tenantId);
        } catch (Throwable $exception) {
            throw new RuntimeException('The CV could not be stored.', 0, $exception);
        }
    }

    private function parseCv(UploadedFile $cv, StoredCandidateDocumentDTO $stored): ParsedCvDTO
    {
        $contents = @file_get_contents($cv->getRealPath() ?: '');

        return is_string($contents)
            ? $this->safeParse($contents, $stored->mimeType, $stored->originalName)
            : ParsedCvDTO::failed();
    }

    private function safeParse(string $contents, string $mimeType, string $fileName): ParsedCvDTO
    {
        try {
            return $this->cvParser->parse($contents, $mimeType, $fileName);
        } catch (Throwable) {
            return ParsedCvDTO::failed();
        }
    }

    private function nextReference(): string
    {
        do {
            $reference = 'APP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (CandidateApplication::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }
}
