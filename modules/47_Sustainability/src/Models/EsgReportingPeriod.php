<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A reporting period (normally a financial year) with the denominators of the intensity ratios. A closed period locks
 * the activity data and the KPI values dated inside it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property string|null $revenue
 * @property string $currency
 * @property string|null $employees_fte
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 */
final class EsgReportingPeriod extends BaseEnterpriseModel
{
    public const string Open = 'open';

    public const string Closed = 'closed';

    /** @var list<string> */
    public const array Statuses = [self::Open, self::Closed];

    protected $table = 'esg_reporting_periods';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
            'revenue' => 'decimal:2',
            'employees_fte' => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::Open;
    }
}
