<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Services\LedgerBalanceReader;
use Modules\GeneralLedger\Services\LedgerReportService;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Translates the trial balance of an entity from its accounting currency into the group currency, with the rates and
 * the rule the existing balance sheet and income statement use for a presentation currency (LedgerReportService, which
 * reads the `exchange_rates` table; concept of IAS 21 / Swiss CO art. 958d para. 3, no compliance claimed):
 *
 * - assets, liabilities: closing rate, the last rate published on or before the period end;
 * - equity: historical rate, the last rate published on or before the start of the fiscal year (an approximation: the
 *   rate of each capital contribution is not tracked, so every equity account is translated at one rate; when no rate
 *   exists before the year start the closing rate is used, as the existing statements do);
 * - revenues and costs: average rate, the mean of the rates published between the year start and the period end (the
 *   figures are year to date), the closing rate when none was published in the range.
 *
 * A missing closing rate stops the consolidation: no rate is guessed. Each translated amount is rounded to 4 decimals,
 * so the translated trial balance of an entity does not add up to zero exactly: the difference is the translation
 * difference, taken to the translation reserve by the calculator.
 *
 * Prior-year results that the entity has not closed into equity (it has no year-end closing entry yet) would make the
 * balance sheet accounts and the income accounts disagree; they are carried on a synthetic equity row `CONS-RETAINED`,
 * at the historical rate, and reported as a warning by the calculator.
 */
final readonly class ConsolidationTranslator
{
    public const string ResidualCode = 'CONS-RETAINED';

    public function __construct(
        private EntityBalanceReader $reader,
        private LedgerReportService $rates,
    ) {}

    /**
     * @return array{tenant_id: string, currency: string, rates: array{closing: string, average: string, historical: string}, rows: list<array{account_id: ?string, account_code: string, account_name: string, account_type: string, normal_balance: string, local_net: string, rate_basis: string, rate: string, group_net: string, is_residual: bool}>}
     *
     * @throws ConsolidationException when a foreign entity has no exchange rate on or before the period end
     */
    public function translate(string $tenantId, string $groupCurrency, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): array
    {
        $currency = TenantAccountingCurrency::of($tenantId);
        $rates = $this->ratesFor($currency, $groupCurrency, $yearStart, $periodEnd);
        $rows = [];
        $localSum = ConsolidationMath::zero();

        foreach ($this->reader->read($tenantId, $yearStart, $periodEnd) as $balance) {
            $basis = $this->basisOf($balance['account_type']);
            $localSum = ConsolidationMath::add($localSum, $balance['net']);
            $rows[] = $this->row($balance, $basis, $rates[$basis]);
        }

        if (! ConsolidationMath::isZero($localSum)) {
            $rows[] = $this->row([
                'account_id' => null,
                'account_code' => self::ResidualCode,
                'account_name' => 'Prior-year results not closed in the entity ledger',
                'account_type' => 'equity',
                'normal_balance' => 'credit',
                'net' => ConsolidationMath::neg($localSum),
            ], 'historical', $rates['historical'], true);
        }

        return [
            'tenant_id' => $tenantId,
            'currency' => $currency,
            'rates' => $rates,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{closing: string, average: string, historical: string}
     *
     * @throws ConsolidationException
     */
    public function ratesFor(string $currency, string $groupCurrency, CarbonImmutable $yearStart, CarbonImmutable $periodEnd): array
    {
        if ($currency === $groupCurrency) {
            return ['closing' => '1.000000', 'average' => '1.000000', 'historical' => '1.000000'];
        }

        try {
            $closing = $this->rates->closingTranslationRate($currency, $groupCurrency, $periodEnd);
        } catch (LedgerPostingException) {
            throw new ConsolidationException("No {$currency}/{$groupCurrency} exchange rate is published on or before {$periodEnd->toDateString()}: publish it before consolidating.");
        }

        return [
            'closing' => $closing,
            'average' => $this->rates->averageTranslationRate($currency, $groupCurrency, $yearStart, $periodEnd, $closing),
            'historical' => $this->rates->historicalTranslationRate($currency, $groupCurrency, $yearStart, $closing),
        ];
    }

    public function basisOf(string $accountType): string
    {
        return match (true) {
            in_array($accountType, LedgerBalanceReader::IncomeTypes, true) => 'average',
            $accountType === 'equity' => 'historical',
            default => 'closing',
        };
    }

    /**
     * @param  array{account_id: ?string, account_code: string, account_name: string, account_type: string, normal_balance: string, net: string}  $balance
     * @return array{account_id: ?string, account_code: string, account_name: string, account_type: string, normal_balance: string, local_net: string, rate_basis: string, rate: string, group_net: string, is_residual: bool}
     */
    private function row(array $balance, string $basis, string $rate, bool $isResidual = false): array
    {
        return [
            'account_id' => $balance['account_id'],
            'account_code' => $balance['account_code'],
            'account_name' => $balance['account_name'],
            'account_type' => $balance['account_type'],
            'normal_balance' => $balance['normal_balance'],
            'local_net' => $balance['net'],
            'rate_basis' => $basis,
            'rate' => $rate,
            'group_net' => ConsolidationMath::mul($balance['net'], $rate),
            'is_residual' => $isResidual,
        ];
    }
}
