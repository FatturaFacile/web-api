<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A manual forecast item: an assumption of the CFO (a certain, probable or estimated flow) projected like the flows read from
 * the ledgers, once or every week, month, quarter or year until its end date.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $label
 * @property string $tier
 * @property string $category
 * @property string $direction
 * @property string $amount
 * @property string $currency
 * @property Carbon $first_date
 * @property string $frequency
 * @property Carbon|null $end_date
 * @property bool $is_active
 * @property string|null $notes
 */
final class ForecastManualItem extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Frequencies = ['once', 'weekly', 'monthly', 'quarterly', 'yearly'];

    /** @var list<string> */
    public const array Categories = ['receivable', 'payable', 'sales_order', 'purchase_order', 'recurring_contract', 'lease', 'payroll', 'tax', 'capex', 'debt_principal', 'debt_interest', 'other'];

    protected $table = 'lp_manual_items';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'first_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The dates of the item between two days, inclusive.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $date = CarbonImmutable::parse($this->first_date)->startOfDay();

        if ($this->frequency === 'once') {
            return $date->betweenIncluded($from, $to) ? [$date] : [];
        }

        $dates = [];
        $end = $this->end_date === null ? $to : CarbonImmutable::parse($this->end_date)->startOfDay()->min($to);

        for ($guard = 0; $date->lessThanOrEqualTo($end) && $guard < 1000; $guard++) {
            if ($date->greaterThanOrEqualTo($from)) {
                $dates[] = $date;
            }

            $date = match ($this->frequency) {
                'weekly' => $date->addWeek(),
                'quarterly' => $date->addMonthsNoOverflow(3),
                'yearly' => $date->addYearNoOverflow(),
                default => $date->addMonthNoOverflow(),
            };
        }

        return $dates;
    }
}
