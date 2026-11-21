<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Queries;

use App\Models\User;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * The source documents behind a KPI card or a chart segment (a month bar, a funnel stage, an aging bucket, a table row).
 */
final readonly class DrillDownKpiToSourceDocumentsQuery
{
    /**
     * @param  array<string, string>  $segment  period (Y-m), bucket, side, stage_id, outcome, contact_id, product_id, department_id, team_id
     */
    public function __construct(
        public ?User $user,
        public CockpitFilter $filter,
        public KpiCode $kpi,
        public array $segment = [],
        public int $page = 1,
        public int $perPage = 25,
        public bool $export = false,
    ) {}
}
