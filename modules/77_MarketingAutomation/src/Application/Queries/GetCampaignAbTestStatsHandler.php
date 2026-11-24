<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Queries;

use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Models\Campaign;

/**
 * Engagement per A/B variant and the winner by the configured criteria (open rate, click rate or attributed revenue).
 */
final class GetCampaignAbTestStatsHandler
{
    /**
     * @return array{criteria: string, winner: string|null, variants: list<array<string, mixed>>}
     */
    public function handle(Campaign $campaign): array
    {
        $criteria = (string) ($campaign->ab_test_split_variant_json['winner_criteria'] ?? 'open_rate');

        $rows = DB::table('ma_messages as m')
            ->leftJoin('ma_attributions as a', static function ($join): void {
                $join->on('a.message_id', '=', 'm.id')->where('a.document_type', '=', 'sales_order')->whereNull('a.deleted_at');
            })
            ->where('m.campaign_id', $campaign->id)->whereNull('m.deleted_at')->whereNotNull('m.sent_at')
            ->groupBy('m.variant')
            ->selectRaw('m.variant as variant, COUNT(DISTINCT m.id) as sent,
                COUNT(DISTINCT CASE WHEN m.first_opened_at IS NOT NULL OR m.first_clicked_at IS NOT NULL THEN m.id END) as opens,
                COUNT(DISTINCT CASE WHEN m.first_clicked_at IS NOT NULL THEN m.id END) as clicks,
                COALESCE(SUM(a.amount_net), 0) as revenue')
            ->get();

        $variants = [];
        foreach ($rows as $row) {
            $sent = max(1, (int) $row->sent);
            $variants[] = [
                'variant' => $row->variant === null ? 'default' : (string) $row->variant,
                'sent' => (int) $row->sent,
                'unique_opens' => (int) $row->opens,
                'unique_clicks' => (int) $row->clicks,
                'open_rate' => round((int) $row->opens / $sent * 100, 2),
                'click_rate' => round((int) $row->clicks / $sent * 100, 2),
                'revenue' => round((float) $row->revenue, 2),
            ];
        }

        $metric = match ($criteria) {
            'click_rate' => 'click_rate',
            'revenue' => 'revenue',
            default => 'open_rate',
        };

        $winner = null;
        if (count($variants) > 1) {
            usort($variants, static fn (array $a, array $b): int => $b[$metric] <=> $a[$metric]);
            $winner = $variants[0][$metric] > ($variants[1][$metric] ?? 0) ? $variants[0]['variant'] : null;
        }

        return ['criteria' => $criteria, 'winner' => $winner, 'variants' => $variants];
    }
}
