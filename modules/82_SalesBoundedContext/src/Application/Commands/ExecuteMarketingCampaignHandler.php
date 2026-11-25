<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\Enums\InsolvencyRiskStatus;
use Modules\SalesBoundedContext\Domain\Enums\RfmSegmentCode;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\MarketingCampaignExecutedEvent;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcMarketingCampaign;

final readonly class ExecuteMarketingCampaignHandler
{
    public function handle(ExecuteMarketingCampaignCommand $command): SbcMarketingCampaign
    {
        $data = $command->payload;
        $consentVerified = (bool) ($data['gdpr_nlpd_lcsl_consent_verified'] ?? true);
        if (! $consentVerified) {
            throw SalesBoundedContextException::invalidStateTransition(
                'GDPR / Swiss nLPD / LCSl marketing consent must be verified before campaign execution.'
            );
        }

        $recency = max(1, min(5, (int) ($data['rfm_recency_score'] ?? 5)));
        $frequency = max(1, min(5, (int) ($data['rfm_frequency_score'] ?? 4)));
        $monetary = max(1, min(5, (int) ($data['rfm_monetary_score'] ?? 5)));
        $rfmAvg = ($recency + $frequency + $monetary) / 3.0;

        $segment = isset($data['rfm_segment'])
            ? RfmSegmentCode::from((string) $data['rfm_segment'])
            : match (true) {
                $rfmAvg >= 4.2 => RfmSegmentCode::Champions,
                $rfmAvg >= 3.2 => RfmSegmentCode::Loyal,
                $rfmAvg >= 2.2 => RfmSegmentCode::AtRisk,
                default => RfmSegmentCode::Hibernating,
            };

        $excludeInsolvent = (bool) ($data['exclude_insolvent_overdue'] ?? true);
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));
        $cost = DecimalMoney::of((float) ($data['campaign_cost'] ?? 1500), $currency);
        $revenue = DecimalMoney::of((float) ($data['attributed_revenue'] ?? 18500), $currency);
        $roiPct = $cost->toFloat() > 0.0
            ? round((($revenue->toFloat() - $cost->toFloat()) / $cost->toFloat()) * 100.0, 2)
            : 0.0;

        $campaign = DB::transaction(function () use (
            $command,
            $data,
            $recency,
            $frequency,
            $monetary,
            $segment,
            $excludeInsolvent,
            $consentVerified,
            $cost,
            $revenue,
            $roiPct,
            $currency,
        ): SbcMarketingCampaign {
            $excludedInsolventCount = 0;
            if ($excludeInsolvent) {
                $excludedInsolventCount = SbcCustomerDossier::query()
                    ->where('tenant_id', $command->tenantId)
                    ->where(function ($query): void {
                        $query->where('overdue_balance', '>', 0)
                            ->orWhereIn('insolvency_status', [
                                InsolvencyRiskStatus::CreditHold->value,
                                InsolvencyRiskStatus::InsolventLegal->value,
                            ]);
                    })
                    ->count();
            }

            $rawAudience = max(1, (int) ($data['target_audience_count'] ?? 250));
            $optedOutSuppressed = max(0, (int) ($data['opted_out_suppressed_count'] ?? 8));
            $eligibleCount = max(0, $rawAudience - $excludedInsolventCount - $optedOutSuppressed);

            return SbcMarketingCampaign::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'campaign_code' => (string) ($data['campaign_code'] ?? 'MKT-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6))),
                'name' => (string) $data['name'],
                'channel' => (string) ($data['channel'] ?? 'dem_email'),
                'rfm_recency_score' => $recency,
                'rfm_frequency_score' => $frequency,
                'rfm_monetary_score' => $monetary,
                'rfm_segment' => $segment,
                'journey_definition' => $data['journey_definition'] ?? [
                    ['step' => 1, 'action' => 'rfm_segment_entry', 'segment' => $segment->value],
                    ['step' => 2, 'action' => 'insolvent_overdue_filter', 'enabled' => $excludeInsolvent],
                    ['step' => 3, 'action' => 'dem_dispatch_and_attribution', 'channel' => (string) ($data['channel'] ?? 'dem_email')],
                ],
                'exclude_insolvent_overdue' => $excludeInsolvent,
                'excluded_insolvent_count' => $excludedInsolventCount,
                'gdpr_nlpd_lcsl_consent_verified' => $consentVerified,
                'opted_out_suppressed_count' => $optedOutSuppressed,
                'eligible_recipients_count' => $eligibleCount,
                'campaign_cost' => $cost->roundHalfUp(),
                'attributed_revenue' => $revenue->roundHalfUp(),
                'roi_pct' => $roiPct,
                'currency' => $currency,
                'status' => (string) ($data['status'] ?? 'active'),
            ]);
        });

        MarketingCampaignExecutedEvent::dispatch(
            $command->tenantId,
            (string) $campaign->id,
            $segment->value,
            (int) $campaign->eligible_recipients_count,
            (int) $campaign->excluded_insolvent_count,
            (float) $campaign->roi_pct,
        );

        return $campaign;
    }
}
