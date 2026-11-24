<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Queries;

use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Domain\Enums\RfmLabel;
use Modules\MarketingAutomation\Models\Segment;

/**
 * RFM matrix of a segment: contacts per label (Champions, Loyal, At Risk, Lost, ...), the R x F heat map cells and
 * the number of customers excluded by the credit semaphore and by the consent rules.
 */
final class GetRfmMatrixHandler
{
    /**
     * @return array{segment_id: string, eligible: int, excluded_insolvent: int, excluded_no_consent: int, last_evaluated_at: string|null, labels: list<array{label: string, count: int}>, cells: list<array{r: int, f: int, count: int, monetary: float}>}
     */
    public function handle(GetRfmMatrixQuery $query): array
    {
        $segment = Segment::query()->where('tenant_id', $query->tenantId)->findOrFail($query->segmentId);
        $breakdown = (array) $segment->rfm_breakdown;

        $labels = array_map(static fn (RfmLabel $label): array => ['label' => $label->value, 'count' => (int) ($breakdown[$label->value] ?? 0)], RfmLabel::cases());

        $cells = DB::table('ma_segment_members')
            ->where('segment_id', $segment->id)->whereNull('deleted_at')
            ->groupBy('r_score', 'f_score')
            ->selectRaw('r_score, f_score, COUNT(*) as n, SUM(monetary_value) as monetary')
            ->orderBy('r_score')->orderBy('f_score')->get();

        return [
            'segment_id' => (string) $segment->id,
            'eligible' => (int) $segment->eligible_contacts_count,
            'excluded_insolvent' => (int) $segment->excluded_insolvent_count,
            'excluded_no_consent' => (int) $segment->excluded_no_consent_count,
            'last_evaluated_at' => $segment->last_evaluated_at?->toIso8601String(),
            'labels' => $labels,
            'cells' => $cells->map(static fn (object $c): array => ['r' => (int) $c->r_score, 'f' => (int) $c->f_score, 'count' => (int) $c->n, 'monetary' => round((float) $c->monetary, 2)])->all(),
        ];
    }
}
