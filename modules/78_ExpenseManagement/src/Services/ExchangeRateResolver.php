<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonInterface;
use Modules\ExpenseManagement\Exceptions\ExpenseException;

/**
 * The rate to convert an expense into the currency of its report: the rate the employee entered (the card statement
 * one), else the latest stored rate on or before the expense date, direct or inverted. Nothing is guessed.
 */
final class ExchangeRateResolver
{
    /**
     * @return array{rate: string, date: string|null}
     */
    public function resolve(string $from, string $to, CarbonInterface $on, ?string $explicit): array
    {
        if ($from === $to) {
            return ['rate' => '1.00000000', 'date' => null];
        }

        if ($explicit !== null && bccomp($explicit, '0', 8) > 0) {
            return ['rate' => bcadd($explicit, '0', 8), 'date' => $on->toDateString()];
        }

        $direct = ExchangeRate::query()->where('source', $from)->where('target', $to)->whereDate('effective_date', '<=', $on->toDateString())->orderByDesc('effective_date')->first();

        if ($direct !== null && bccomp((string) $direct->rate, '0', 8) > 0) {
            return ['rate' => bcadd((string) $direct->rate, '0', 8), 'date' => (string) $direct->effective_date];
        }

        $inverse = ExchangeRate::query()->where('source', $to)->where('target', $from)->whereDate('effective_date', '<=', $on->toDateString())->orderByDesc('effective_date')->first();

        if ($inverse !== null && bccomp((string) $inverse->rate, '0', 8) > 0) {
            return ['rate' => bcdiv('1', (string) $inverse->rate, 8), 'date' => (string) $inverse->effective_date];
        }

        throw new ExpenseException('currency_rate_missing', 422, ['from' => $from, 'to' => $to]);
    }
}
