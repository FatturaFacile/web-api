<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RejectionReasonCode;

/**
 * `MoveCandidateStageCommand`: Kanban drag and drop or explicit stage change.
 */
final readonly class MoveCandidateStageDTO
{
    public function __construct(
        public string $applicationId,
        public ApplicationStage $stage,
        public ?int $boardPosition = null,
        public ?RejectionReasonCode $rejectionReasonCode = null,
        public ?string $rejectionNote = null,
        public ?bool $sendEmail = null,
        public ?string $note = null,
        public ?string $actorUserId = null
    ) {}
}
