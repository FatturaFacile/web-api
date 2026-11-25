<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;

/**
 * Saved Management Review (Riesame della Direzione) snapshot for UNI EN ISO 9001:2015 § 9.1.2.
 *
 * @property string $title
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property ReportGroupBy $group_by
 * @property array<string, mixed>|null $filters
 * @property array<string, mixed> $metrics_snapshot
 * @property string|null $management_review_notes
 * @property string|null $generated_by_user_id
 * @property string|null $exported_format
 */
final class Iso9001SatisfactionReport extends BaseEnterpriseModel
{
    protected $table = 'iso_9001_satisfaction_reports';

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'group_by' => ReportGroupBy::class,
            'filters' => 'array',
            'metrics_snapshot' => 'array',
        ];
    }
}
