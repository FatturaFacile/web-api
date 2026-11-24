<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\CampaignServiceInterface;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignMember;

final readonly class CampaignService implements CampaignServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createCampaign(array $data, string $actorUserId): Campaign
    {
        return DB::transaction(function () use ($data) {
            $campaign = Campaign::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $data['tenant_id'],
                'campaign_type_id' => $data['campaign_type_id'] ?? null,
                'code' => $data['code'] ?? 'CMP-'.strtoupper(Str::random(6)),
                'name' => $data['name'],
                'status' => $data['status'] ?? 'planned',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'budgeted_cost' => (float) ($data['budgeted_cost'] ?? 0.0),
                'actual_cost' => (float) ($data['actual_cost'] ?? 0.0),
                'expected_revenue' => (float) ($data['expected_revenue'] ?? 0.0),
                'actual_revenue' => (float) ($data['actual_revenue'] ?? 0.0),
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->auditLedger->record(
                action: 'CRM_CAMPAIGN_CREATED',
                model: $campaign,
                payloadBefore: null,
                payloadAfter: $campaign->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            return $campaign;
        });
    }

    public function addMember(Campaign $campaign, ?string $leadId, ?string $contactId, string $status = 'sent'): CampaignMember
    {
        return CampaignMember::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'lead_id' => $leadId,
            'contact_id' => $contactId,
            'status' => $status,
            'first_associated_at' => now(),
        ]);
    }

    public function calculateROI(Campaign $campaign): array
    {
        $actualCost = (float) $campaign->actual_cost;
        $actualRev = (float) $campaign->actual_revenue;
        $budgetCost = (float) $campaign->budgeted_cost;
        $expectedRev = (float) $campaign->expected_revenue;

        $roiPercent = $actualCost > 0 ? round((($actualRev - $actualCost) / $actualCost) * 100.0, 2) : 0.00;

        $totalMembers = $campaign->members()->count();
        $convertedMembers = $campaign->members()->where('status', 'converted')->count();

        return [
            'budgeted_cost' => $budgetCost,
            'actual_cost' => $actualCost,
            'expected_revenue' => $expectedRev,
            'actual_revenue' => $actualRev,
            'roi_percentage' => $roiPercent,
            'total_members' => $totalMembers,
            'converted_members' => $convertedMembers,
        ];
    }
}
