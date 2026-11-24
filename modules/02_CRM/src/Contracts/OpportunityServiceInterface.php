<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\DTOs\CreateOpportunityDTO;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Models\Opportunity;
use Modules\Invoicing\Models\SalesQuote;

interface OpportunityServiceInterface
{
    public function createOpportunity(CreateOpportunityDTO $dto, string $actorUserId): Opportunity;

    public function changeStage(Opportunity $opp, UpdateOpportunityStageDTO $dto, string $actorUserId): Opportunity;

    public function calculateExpectedRevenue(Opportunity $opp): float;

    /**
     * Reorders a deal inside its current column.
     */
    public function reposition(Opportunity $opp, int $position, string $actorUserId): Opportunity;

    /**
     * Replaces the quick note of a deal, or appends a timestamped line to it.
     */
    public function updateNotes(Opportunity $opp, string $notes, bool $append, string $actorUserId): Opportunity;

    public function requestQuote(Opportunity $opp, string $actorUserId): SalesQuote;

    /**
     * @return array{opportunity: Opportunity, project_id: string}
     */
    public function convertToProject(Opportunity $opp, string $actorUserId): array;

    public function checkWorkflowRequirement(Opportunity $opp, string $targetStageCode): ?string;
}
