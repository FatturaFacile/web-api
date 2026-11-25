<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\Enums\LeadLifecycleStage;
use Modules\SalesBoundedContext\Events\LeadCapturedAndScoredEvent;
use Modules\SalesBoundedContext\Models\SbcOmnichannelLead;

final readonly class CaptureAndScoreLeadHandler
{
    public function handle(CaptureAndScoreLeadCommand $command): SbcOmnichannelLead
    {
        $data = $command->payload;
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';
        $fiscal = isset($data['fiscal_identifier']) ? strtoupper(trim((string) $data['fiscal_identifier'])) : '';
        $dedupSource = $email !== '' ? $email : ($fiscal !== '' ? $fiscal : strtolower(trim((string) ($data['full_name'] ?? ''))));
        $dedupHash = hash('sha256', $command->tenantId.'|'.$dedupSource);

        $demographic = (int) ($data['demographic_score'] ?? ($email !== '' ? 25 : 10));
        $behavioral = (int) ($data['behavioral_score'] ?? match ((string) ($data['source_channel'] ?? 'web_form')) {
            'quote_request', 'erp_trial' => 40,
            'webinar', 'trade_fair' => 30,
            default => 20,
        });
        $firmographic = (int) ($data['firmographic_score'] ?? ($fiscal !== '' ? 30 : 15));
        $totalScore = min(100, $demographic + $behavioral + $firmographic);

        $country = strtoupper((string) ($data['country'] ?? 'CH'));
        $territory = (string) ($data['assigned_territory'] ?? ($country === 'IT' ? 'IT-NORTH-MILAN' : 'CH-TI-ZURICH'));
        $assignedRep = (string) ($data['assigned_rep_name'] ?? ($country === 'IT' ? 'Giulia Conti' : 'Lukas Meier'));

        $stage = isset($data['lifecycle_stage'])
            ? LeadLifecycleStage::from((string) $data['lifecycle_stage'])
            : ($totalScore >= 75 ? LeadLifecycleStage::Sql : ($totalScore >= 50 ? LeadLifecycleStage::Mql : LeadLifecycleStage::Captured));

        $lead = DB::transaction(function () use ($command, $data, $email, $fiscal, $dedupHash, $demographic, $behavioral, $firmographic, $totalScore, $country, $territory, $assignedRep, $stage): SbcOmnichannelLead {
            $existing = SbcOmnichannelLead::query()
                ->where('tenant_id', $command->tenantId)
                ->where('dedup_hash', $dedupHash)
                ->first();

            return SbcOmnichannelLead::create([
                'tenant_id' => $command->tenantId,
                'duplicate_of_id' => $existing?->id,
                'lead_code' => (string) ($data['lead_code'] ?? 'LEAD-'.strtoupper(Str::random(6))),
                'source_channel' => (string) ($data['source_channel'] ?? 'web_form'),
                'full_name' => (string) $data['full_name'],
                'company_name' => $data['company_name'] ?? null,
                'email' => $email !== '' ? $email : null,
                'phone' => $data['phone'] ?? null,
                'country' => $country,
                'fiscal_identifier' => $fiscal !== '' ? $fiscal : null,
                'dedup_hash' => $dedupHash,
                'is_duplicate' => $existing !== null,
                'demographic_score' => $demographic,
                'behavioral_score' => $behavioral,
                'firmographic_score' => $firmographic,
                'total_score' => $totalScore,
                'lifecycle_stage' => $stage,
                'assigned_rep_name' => $assignedRep,
                'assigned_territory' => $territory,
                'routing_rule_applied' => (string) ($data['routing_rule_applied'] ?? "AUTO_TERRITORY_{$country}"),
                'utm_metadata' => $data['utm_metadata'] ?? [],
            ]);
        });

        LeadCapturedAndScoredEvent::dispatch(
            $command->tenantId,
            (string) $lead->id,
            $totalScore,
            (bool) $lead->is_duplicate,
            $lead->assigned_territory,
        );

        return $lead;
    }
}
