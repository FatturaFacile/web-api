<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

use Modules\HumanResources\Enums\OnboardingStatus;

final readonly class OnboardingStartResultDTO
{
    public function __construct(
        public OnboardingStatus $status,
        public ?string $checklistId = null
    ) {}

    /**
     * @return array{status: string, checklist_id: string|null}
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'checklist_id' => $this->checklistId];
    }
}
