<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A recurring cash flow the liquidity forecast projects (rent, loan instalments, salaries, leases...): an amount in or
 * out every week, month, quarter or year from its next date until its end date.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $name
 * @property string $category
 * @property string $direction
 * @property string $amount
 * @property string $currency
 * @property string $frequency
 * @property Carbon $next_date
 * @property Carbon|null $end_date
 * @property bool $is_active
 */
final class RecurringCashItem extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Frequencies = ['weekly', 'monthly', 'quarterly', 'yearly'];

    protected $table = 'treasury_recurring_items';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'next_date' => 'date',
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
        $dates = [];
        $date = CarbonImmutable::parse($this->next_date)->startOfDay();
        $end = $this->end_date === null ? $to : CarbonImmutable::parse($this->end_date)->min($to);

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
