<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CustomerSatisfaction\Models\Iso9001SatisfactionReport;

/**
 * @mixin Iso9001SatisfactionReport
 */
final class Iso9001ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'group_by' => $this->group_by->value,
            'filters' => $this->filters ?? [],
            'metrics_snapshot' => $this->when($request->routeIs('customer-satisfaction.reports.show'), $this->metrics_snapshot),
            'headline' => [
                'nps' => $this->metrics_snapshot['overview']['kpis']['nps']['score'] ?? null,
                'csat_pct' => $this->metrics_snapshot['overview']['kpis']['csat']['positive_pct'] ?? null,
                'ces_average' => $this->metrics_snapshot['overview']['kpis']['ces']['average'] ?? null,
                'sla_compliance' => $this->metrics_snapshot['overview']['closed_loop']['sla_compliance'] ?? null,
            ],
            'management_review_notes' => $this->management_review_notes,
            'generated_by_user_id' => $this->generated_by_user_id,
            'exported_format' => $this->exported_format,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
