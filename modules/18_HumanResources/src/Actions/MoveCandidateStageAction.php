<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Contracts\CandidateEmailSenderInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\DTOs\MoveCandidateStageDTO;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Events\CandidateStageChangedEvent;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\ApplicationStageHistory;
use Modules\HumanResources\Models\CandidateApplication;

/**
 * `MoveCandidateStageCommand`: validates the transition against the stage table and the tenant pipeline, records
 * the history, re-orders the Kanban column and sends the stage email after commit.
 */
final readonly class MoveCandidateStageAction
{
    public function __construct(
        private RecruitmentServiceInterface $recruitment,
        private CandidateEmailSenderInterface $emailSender
    ) {}

    public function execute(MoveCandidateStageDTO $dto): CandidateApplication
    {
        $settings = $this->recruitment->settings();

        $result = DB::transaction(function () use ($dto, $settings): array {
            /** @var CandidateApplication $application */
            $application = CandidateApplication::query()->lockForUpdate()->findOrFail($dto->applicationId);

            if ($application->isAnonymized()) {
                throw RecruitingRuleViolationException::candidateAnonymized();
            }

            $from = $application->stage;
            $to = $dto->stage;

            if ($from === $to) {
                if ($dto->boardPosition !== null) {
                    $this->placeInColumn($application, $dto->boardPosition);
                }

                return [$application, false];
            }

            if (! $from->canTransitionTo($to)) {
                throw RecruitingRuleViolationException::invalidStageTransition($from->value, $to->value);
            }

            if (! $settings->stageEnabled($to)) {
                throw RecruitingRuleViolationException::stageDisabled($to->value);
            }

            if ($to === ApplicationStage::Rejected && $dto->rejectionReasonCode === null) {
                throw ValidationException::withMessages([
                    'rejection_reason_code' => ['A rejection reason code is required to reject a candidate.'],
                ]);
            }

            $application->stage = $to;
            $application->stage_entered_at = now();
            $application->rejection_reason_code = $to === ApplicationStage::Rejected ? $dto->rejectionReasonCode->value : null;
            $application->rejection_note = $to === ApplicationStage::Rejected ? $dto->rejectionNote : null;
            $application->board_position = $this->recruitment->nextBoardPosition((string) $application->job_opening_id, $to);
            $application->save();

            if ($dto->boardPosition !== null) {
                $this->placeInColumn($application, $dto->boardPosition);
            }

            $actorUserId = $dto->actorUserId !== null && Str::isUuid($dto->actorUserId) ? $dto->actorUserId : null;

            ApplicationStageHistory::create([
                'application_id' => $application->id,
                'from_stage' => $from->value,
                'to_stage' => $to->value,
                'actor_user_id' => $actorUserId,
                'note' => $dto->note,
            ]);

            event(new CandidateStageChangedEvent(
                applicationId: (string) $application->id,
                jobOpeningId: (string) $application->job_opening_id,
                fromStage: $from->value,
                toStage: $to->value,
                actorUserId: $actorUserId,
                tenantId: (string) $application->tenant_id,
                companyId: (string) $application->tenant_id
            ));

            return [$application, true];
        });

        /** @var CandidateApplication $application */
        [$application, $moved] = $result;

        if ($moved && ($dto->sendEmail ?? $settings->stageAutoEmail($dto->stage))) {
            $this->emailSender->sendStageEmail($application, $dto->stage);
        }

        return $application;
    }

    /**
     * Insert the application at a 0-based index of its column and renumber the column.
     */
    private function placeInColumn(CandidateApplication $application, int $position): void
    {
        $ids = CandidateApplication::query()
            ->where('job_opening_id', $application->job_opening_id)
            ->where('stage', $application->stage->value)
            ->whereKeyNot($application->id)
            ->orderBy('board_position')
            ->orderBy('created_at')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $position = max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [(string) $application->id]);

        foreach ($ids as $index => $id) {
            CandidateApplication::query()->whereKey($id)->where('board_position', '!=', $index)->update(['board_position' => $index]);
        }

        $application->board_position = $position;
        $application->syncOriginalAttribute('board_position');
    }
}
