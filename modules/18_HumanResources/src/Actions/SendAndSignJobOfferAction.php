<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Contracts\CandidateEmailSenderInterface;
use Modules\HumanResources\Contracts\DigitalSignatureProviderInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\DTOs\SendJobOfferDTO;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\ContractType;
use Modules\HumanResources\Enums\OfferStatus;
use Modules\HumanResources\Enums\SignatureStatus;
use Modules\HumanResources\Events\CandidateStageChangedEvent;
use Modules\HumanResources\Events\JobOfferSentEvent;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\ApplicationStageHistory;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOffer;
use Modules\HumanResources\Services\JobOfferLetterRenderer;

/**
 * `SendAndSignJobOfferCommand`: creates the offer letter, renders it, moves the candidate to `offer_extended` and
 * requests the signature through the configured provider. Also records the signature or the refusal.
 */
final readonly class SendAndSignJobOfferAction
{
    public function __construct(
        private RecruitmentServiceInterface $recruitment,
        private DigitalSignatureProviderInterface $signatureProvider,
        private JobOfferLetterRenderer $renderer,
        private CandidateEmailSenderInterface $emailSender
    ) {}

    public function execute(SendJobOfferDTO $dto): JobOffer
    {
        $settings = $this->recruitment->settings();

        /** @var array{0: JobOffer, 1: bool} $result */
        $result = DB::transaction(function () use ($dto, $settings): array {
            /** @var CandidateApplication $application */
            $application = CandidateApplication::query()->with('jobOpening')->lockForUpdate()->findOrFail($dto->applicationId);

            if ($application->isAnonymized()) {
                throw RecruitingRuleViolationException::candidateAnonymized();
            }

            if ($application->offers()->whereIn('status', array_map(static fn (OfferStatus $status): string => $status->value, OfferStatus::active()))->exists()) {
                throw RecruitingRuleViolationException::offerAlreadyExists();
            }

            $from = $application->stage;
            if ($from !== ApplicationStage::OfferExtended && ! $from->canTransitionTo(ApplicationStage::OfferExtended)) {
                throw RecruitingRuleViolationException::invalidStageTransition($from->value, ApplicationStage::OfferExtended->value);
            }

            $opening = $application->jobOpening;
            $offer = new JobOffer([
                'application_id' => $application->id,
                'proposed_salary' => round($dto->proposedSalary, 2),
                'currency' => strtoupper($dto->currency ?? ($opening?->salary_currency ?: 'EUR')),
                'start_date' => $dto->startDate,
                'employment_type' => $dto->employmentType ?? ($opening?->employment_type ?: 'full_time'),
                'contract_type' => $dto->contractType ?? ContractType::Permanent->value,
                'weekly_hours' => $dto->weeklyHours ?? 40.0,
                'probation_months' => $dto->probationMonths ?? 3,
                'valid_until' => $dto->validUntil,
                'status' => OfferStatus::Draft,
                'signature_status' => SignatureStatus::NotRequested,
                'signature_provider' => $this->signatureProvider->name(),
                'locale' => $application->locale,
                'created_by' => $dto->actorUserId !== null && Str::isUuid($dto->actorUserId) ? $dto->actorUserId : null,
            ]);
            $offer->rendered_body = $this->renderer->render($offer, $application);
            $offer->save();

            $moved = false;
            if ($from !== ApplicationStage::OfferExtended && $settings->stageEnabled(ApplicationStage::OfferExtended)) {
                $application->stage = ApplicationStage::OfferExtended;
                $application->stage_entered_at = now();
                $application->board_position = $this->recruitment->nextBoardPosition((string) $application->job_opening_id, ApplicationStage::OfferExtended);
                $application->save();

                $actorUserId = $dto->actorUserId !== null && Str::isUuid($dto->actorUserId) ? $dto->actorUserId : null;
                ApplicationStageHistory::create([
                    'application_id' => $application->id,
                    'from_stage' => $from->value,
                    'to_stage' => ApplicationStage::OfferExtended->value,
                    'actor_user_id' => $actorUserId,
                ]);

                event(new CandidateStageChangedEvent(
                    applicationId: (string) $application->id,
                    jobOpeningId: (string) $application->job_opening_id,
                    fromStage: $from->value,
                    toStage: ApplicationStage::OfferExtended->value,
                    actorUserId: $actorUserId,
                    tenantId: (string) $application->tenant_id,
                    companyId: (string) $application->tenant_id
                ));
                $moved = true;
            }

            return [$offer, $moved];
        });

        [$offer, $moved] = $result;

        if ($dto->send) {
            $offer = $this->send($offer);
        }

        if ($moved && $settings->stageAutoEmail(ApplicationStage::OfferExtended)) {
            /** @var CandidateApplication $application */
            $application = CandidateApplication::query()->findOrFail($offer->application_id);
            $this->emailSender->sendStageEmail($application, ApplicationStage::OfferExtended);
        }

        return $offer;
    }

    /**
     * Request the signature of a draft offer (outside of the creation transaction: providers may be remote).
     */
    public function send(JobOffer $offer): JobOffer
    {
        $signature = $this->signatureProvider->requestSignature($offer);

        $offer->forceFill([
            'status' => OfferStatus::Sent,
            'signature_status' => $signature->status,
            'signature_external_id' => $signature->externalId,
            'sent_at' => now(),
        ])->save();

        /** @var CandidateApplication|null $application */
        $application = CandidateApplication::query()->find($offer->application_id);

        event(new JobOfferSentEvent(
            offerId: (string) $offer->id,
            applicationId: (string) $offer->application_id,
            jobOpeningId: (string) ($application?->job_opening_id ?? ''),
            signatureProvider: (string) $offer->signature_provider,
            tenantId: (string) $offer->tenant_id,
            companyId: (string) $offer->tenant_id
        ));

        return $offer;
    }

    /**
     * Record the acceptance of the candidate (manual provider) or the callback of a signature provider.
     */
    public function markSigned(JobOffer $offer, ?string $signedAt = null): JobOffer
    {
        return DB::transaction(function () use ($offer, $signedAt): JobOffer {
            /** @var JobOffer $offer */
            $offer = JobOffer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->assertApplicationNotAnonymized($offer);

            if ($offer->status === OfferStatus::Signed) {
                return $offer;
            }

            $application = CandidateApplication::query()->find($offer->application_id);
            if ($application !== null && $application->stage === ApplicationStage::Rejected) {
                throw RecruitingRuleViolationException::invalidStageTransition(ApplicationStage::Rejected->value, ApplicationStage::Hired->value);
            }

            if (! in_array($offer->status, [OfferStatus::Draft, OfferStatus::Sent], true)) {
                throw ValidationException::withMessages([
                    'offer' => ["An offer in status '{$offer->status->value}' cannot be signed."],
                ]);
            }

            $offer->forceFill([
                'status' => OfferStatus::Signed,
                'signature_status' => SignatureStatus::Signed,
                'signed_at' => $signedAt !== null ? Carbon::parse($signedAt) : now(),
                'sent_at' => $offer->sent_at ?? now(),
            ])->save();

            return $offer;
        });
    }

    public function decline(JobOffer $offer, ?string $note = null): JobOffer
    {
        return DB::transaction(function () use ($offer, $note): JobOffer {
            /** @var JobOffer $offer */
            $offer = JobOffer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->assertApplicationNotAnonymized($offer);

            if ($offer->status === OfferStatus::Declined) {
                return $offer;
            }

            if (! in_array($offer->status, [OfferStatus::Draft, OfferStatus::Sent], true)) {
                throw ValidationException::withMessages([
                    'offer' => ["An offer in status '{$offer->status->value}' cannot be declined."],
                ]);
            }

            $offer->forceFill([
                'status' => OfferStatus::Declined,
                'signature_status' => SignatureStatus::Declined,
                'declined_at' => now(),
                'decline_note' => $note,
            ])->save();

            return $offer;
        });
    }

    private function assertApplicationNotAnonymized(JobOffer $offer): void
    {
        /** @var CandidateApplication|null $application */
        $application = CandidateApplication::query()->find($offer->application_id);

        if ($application === null || $application->isAnonymized()) {
            throw RecruitingRuleViolationException::candidateAnonymized();
        }
    }
}
