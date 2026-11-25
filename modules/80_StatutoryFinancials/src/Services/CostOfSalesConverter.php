<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Support\Money;

/**
 * Converts the income statement by nature of expense (Gesamtkostenverfahren, CO Art. 959b para. 2) into the income statement by
 * function (Umsatzkostenverfahren, para. 3).
 *
 * Material and third-party costs belong to the cost of goods and services sold, reduced by the stock change and the own work
 * capitalised; personnel, other operating expenses and depreciation are split between the cost of sales and the administrative and
 * selling expenses with the shares (percent to the cost of sales) set in the options of the dossier (`cogs_allocation`).
 * Without a share the whole amount is administrative and selling expense.
 */
final class CostOfSalesConverter
{
    /**
     * @param  array<string, string>  $totalCost  Leaves of the total cost layout (codes ch.is.tc.*).
     * @param  array<string, string>  $allocation  Percent of personnel, other_opex and depr that belongs to the cost of sales.
     * @return array<string, string> Leaves of the cost of sales layout (codes ch.is.cs.*).
     */
    public function convert(array $totalCost, array $allocation = []): array
    {
        $get = static fn (string $key): string => Money::from($totalCost['ch.is.tc.'.$key] ?? '0');
        $cogs = $get('material');
        $admin = '0';

        foreach (['personnel', 'other_opex', 'depr'] as $key) {
            $share = Money::compare((string) ($allocation[$key] ?? '0'), '0') > 0 ? Money::min((string) $allocation[$key], '100') : '0';
            $toCogs = Money::mul($get($key), Money::div($share, '100') ?? '0');
            $cogs = Money::add($cogs, $toCogs);
            $admin = Money::add($admin, Money::sub($get($key), $toCogs));
        }

        $cogs = Money::sub(Money::sub($cogs, $get('inv_change')), $get('own_work'));

        return [
            'ch.is.cs.rev' => $get('rev'),
            'ch.is.cs.cogs' => $cogs,
            'ch.is.cs.admin_sales' => $admin,
            'ch.is.cs.fin_exp' => $get('fin_exp'),
            'ch.is.cs.fin_inc' => $get('fin_inc'),
            'ch.is.cs.nonop' => $get('nonop'),
            'ch.is.cs.extra' => $get('extra'),
            'ch.is.cs.tax' => $get('tax'),
        ];
    }
}
