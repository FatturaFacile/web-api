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

    public function requestQuote(Opportunity $opp, string $actorUserId): SalesQuote;

    /**
     * @return array{opportunity: Opportunity, project_id: string}
     */
    public function convertToProject(Opportunity $opp, string $actorUserId): array;

    public function checkWorkflowRequirement(Opportunity $opp, string $targetStageCode): ?string;
}
