<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignMember;

interface CampaignServiceInterface
{
    public function createCampaign(array $data, string $actorUserId): Campaign;

    public function addMember(Campaign $campaign, ?string $leadId, ?string $contactId, string $status = 'sent'): CampaignMember;

    /**
     * @return array{budgeted_cost: float, actual_cost: float, expected_revenue: float, actual_revenue: float, roi_percentage: float, total_members: int, converted_members: int}
     */
    public function calculateROI(Campaign $campaign): array;
}
