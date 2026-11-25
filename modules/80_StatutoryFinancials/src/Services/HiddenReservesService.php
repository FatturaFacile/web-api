<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Hidden reserves (stille Reserven) of the Swiss annual accounts: the statutory view of the CO keeps them undisclosed (the net release
 * above the reserves created must be shown in the Anhang, Art. 959c para. 1 no. 3 CO), the Swiss GAAP FER view does not allow them and
 * restates equity and result net of the deferred tax on the reserves.
 */
final class HiddenReservesService
{
    /**
     * @param  array<string, mixed>  $config  {enabled, tax_rate_percent, items: [{label, book_value, fer_value}], reserves_created, reserves_released}
     * @return array<string, mixed>
     */
    public function evaluate(AccountingStandard $standard, array $config, string $reportedEquity, string $reportedProfit): array
    {
        if (($config['enabled'] ?? false) !== true) {
            return ['enabled' => false];
        }

        $rate = Money::div((string) ($config['tax_rate_percent'] ?? '0'), '100') ?? '0';
        $items = [];
        $hidden = '0';

        foreach ((array) ($config['items'] ?? []) as $item) {
            $amount = Money::sub((string) ($item['fer_value'] ?? '0'), (string) ($item['book_value'] ?? '0'));
            $items[] = ['label' => (string) ($item['label'] ?? ''), 'book_value' => Money::from($item['book_value'] ?? '0'), 'fer_value' => Money::from($item['fer_value'] ?? '0'), 'hidden_reserve' => $amount];
            $hidden = Money::add($hidden, $amount);
        }

        $deferredTax = Money::mul(Money::isPositive($hidden) ? $hidden : '0', $rate);
        $created = Money::from($config['reserves_created'] ?? '0');
        $released = Money::from($config['reserves_released'] ?? '0');
        $netRelease = Money::sub($released, $created);
        $netAfterTax = Money::mul($netRelease, Money::sub('1', $rate));

        return [
            'enabled' => true,
            'tax_rate_percent' => Money::from($config['tax_rate_percent'] ?? '0'),
            'items' => $items,
            'total_hidden_reserves' => $hidden,
            'deferred_tax_on_reserves' => $deferredTax,
            'statutory_view' => ['equity' => $reportedEquity, 'profit' => $reportedProfit],
            'fer_view' => [
                'equity' => Money::sub(Money::add($reportedEquity, $hidden), $deferredTax),
                'profit' => Money::sub($reportedProfit, $netAfterTax),
            ],
            'net_release' => $netRelease,
            'anhang_disclosure_required' => Money::isPositive($netRelease),
            'warnings' => $standard === AccountingStandard::ChSwissGaapFer ? ['hidden_reserves_not_permitted_fer'] : [],
        ];
    }
}
