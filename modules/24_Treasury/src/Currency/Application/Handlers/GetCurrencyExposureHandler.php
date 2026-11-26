<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Carbon\CarbonImmutable;
use Modules\Treasury\Currency\Application\Queries\GetCurrencyExposureQuery;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\RevaluationLineType;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyPositionProvider;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;

/**
 * The currency exposure dashboard: per foreign currency the open receivables, payables, bank and cash balances in the currency,
 * their historical value (book value in the functional currency), their current value at the rate of the day, and the
 * unrealised gain or loss. A currency without a rate within the window is reported with `rate_missing` and no current value:
 * nothing is invented.
 */
final readonly class GetCurrencyExposureHandler
{
    public function __construct(
        private CurrencyPositionProvider $positions,
        private CurrencySettingsRepository $settings,
        private RateSelector $selector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetCurrencyExposureQuery $query): array
    {
        $asOf = CarbonImmutable::parse($query->asOf)->toDateString();
        $functional = $this->settings->effective($query->tenantId)->functionalCurrency;
        /** @var array<string, array{receivables: string, payables: string, bank: string, cash: string, book: string, balance: string}> $byCurrency */
        $byCurrency = [];

        foreach ($this->positions->positions($query->tenantId, $functional, $asOf) as $position) {
            $currency = $position['currency'];
            $item = $byCurrency[$currency] ?? ['receivables' => '0', 'payables' => '0', 'bank' => '0', 'cash' => '0', 'book' => '0', 'balance' => '0'];
            $balance = $position['currency_balance'];
            $bucket = match ($position['line_type']) {
                RevaluationLineType::Receivable => 'receivables',
                RevaluationLineType::Payable => 'payables',
                RevaluationLineType::Bank => 'bank',
                RevaluationLineType::Cash => 'cash',
            };

            // Payables are carried negative: the dashboard shows what is owed as a positive amount.
            $item[$bucket] = Dec::add($item[$bucket], $position['line_type'] === RevaluationLineType::Payable ? Dec::neg($balance) : $balance);
            $item['balance'] = Dec::add($item['balance'], $balance);
            $item['book'] = Dec::add($item['book'], $position['book_value']);
            $byCurrency[$currency] = $item;
        }

        ksort($byCurrency);
        $items = [];
        $totals = ['historical_value' => '0', 'current_value' => '0', 'unrealized_gain_loss' => '0'];

        foreach ($byCurrency as $currency => $item) {
            $rate = $this->closingRate($currency, $functional, $asOf, $query->tenantId);
            $historical = Dec::money($item['book']);
            $current = $rate === null ? null : Dec::money(Dec::mul($item['balance'], $rate));
            $gainLoss = $current === null ? null : Dec::money(Dec::sub($current, $historical));

            if ($current !== null && $gainLoss !== null) {
                $totals['historical_value'] = Dec::add($totals['historical_value'], $historical);
                $totals['current_value'] = Dec::add($totals['current_value'], $current);
                $totals['unrealized_gain_loss'] = Dec::add($totals['unrealized_gain_loss'], $gainLoss);
            }

            $items[] = [
                'currency' => $currency,
                'receivables' => Dec::money($item['receivables']),
                'payables' => Dec::money($item['payables']),
                'bank' => Dec::money($item['bank']),
                'cash' => Dec::money($item['cash']),
                'net_amount' => Dec::money($item['balance']),
                'historical_value' => $historical,
                'current_value' => $current,
                'closing_rate' => $rate,
                'unrealized_gain_loss' => $gainLoss,
                'rate_missing' => $rate === null,
            ];
        }

        return [
            'as_of' => $asOf,
            'functional_currency' => $functional,
            'totals' => ['historical_value' => Dec::money($totals['historical_value']), 'current_value' => Dec::money($totals['current_value']), 'unrealized_gain_loss' => Dec::money($totals['unrealized_gain_loss'])],
            'items' => $items,
        ];
    }

    private function closingRate(string $currency, string $functional, string $asOf, string $tenantId): ?string
    {
        try {
            return $this->selector->select(CurrencyPair::of($currency, $functional), $asOf, $tenantId, RateType::Mid)->rate->value;
        } catch (ExchangeRateMissingException) {
            return null;
        }
    }
}
