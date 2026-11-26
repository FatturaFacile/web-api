<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Currency\Application\Commands\SimulateFxRevaluationCommand;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Enums\RevaluationPeriodType;
use Modules\Treasury\Currency\Domain\Enums\RevaluationStatus;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\Services\UnrealizedFxRevaluator;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluationLine;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyPositionProvider;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;

/**
 * Simulates the period-end revaluation: values the open receivables and payables and the foreign-currency bank and cash accounts
 * at the closing rate of the period end (the closing rate, otherwise the daily rate of the five-day window) and keeps the result
 * as a draft with its lines. A currency without a closing rate refuses the simulation (422 `exchange_rate_missing`): a period is
 * never revalued with an invented rate. Nothing is posted until the draft is posted.
 */
final readonly class SimulateFxRevaluationHandler
{
    public function __construct(
        private CurrencyPositionProvider $positions,
        private CurrencySettingsRepository $settings,
        private RateSelector $selector,
        private UnrealizedFxRevaluator $revaluator,
    ) {}

    /**
     * @throws CurrencyDomainException
     * @throws ExchangeRateMissingException
     */
    public function handle(SimulateFxRevaluationCommand $command): CurrencyFxRevaluation
    {
        $periodEnd = CarbonImmutable::parse($command->periodEnd);
        $this->assertPeriodEnd($periodEnd, $command->periodType);

        $settings = $this->settings->effective($command->tenantId);
        $currencies = $command->currencies === null || $command->currencies === [] ? null : array_values(array_unique(array_map('strtoupper', $command->currencies)));
        $positions = $this->positions->positions($command->tenantId, $settings->functionalCurrency, $periodEnd->toDateString(), $currencies);

        if ($positions === []) {
            throw new CurrencyDomainException(__('currency_fx.errors.nothing_to_revalue', ['date' => $periodEnd->toDateString()]), 'nothing_to_revalue', 422, 'period_end');
        }

        $rates = [];
        $lines = [];

        foreach ($positions as $position) {
            $currency = $position['currency'];
            $rates[$currency] ??= $this->closingRate($currency, $settings->functionalCurrency, $periodEnd->toDateString(), $command->tenantId);
            $lines[] = $this->revaluator->revalue($position, $rates[$currency], $settings->gainTreatment);
        }

        $totals = $this->revaluator->totals($lines);

        return DB::transaction(function () use ($command, $periodEnd, $settings, $currencies, $lines, $totals): CurrencyFxRevaluation {
            $revaluation = new CurrencyFxRevaluation;
            $revaluation->forceFill([
                'tenant_id' => $command->tenantId,
                'period_end' => $periodEnd->toDateString(),
                'period_type' => $command->periodType->value,
                'status' => RevaluationStatus::Draft->value,
                'functional_currency' => $settings->functionalCurrency,
                'currencies' => $currencies,
                'total_gain' => $totals['total_gain'],
                'total_loss' => $totals['total_loss'],
                'net_result' => $totals['net_result'],
                'deferred_gain' => $totals['deferred_gain'],
                // Italian companies keep the net unrealised gain recognised in the income statement as a non-distributable reserve (Art. 2426 n. 8-bis c.c.).
                'reserve_amount' => $settings->country === 'IT' ? $totals['reserve_amount'] : '0.00',
                'reverse_on' => $periodEnd->addDay()->toDateString(),
                'created_by' => $command->actorId,
            ]);
            $revaluation->save();

            foreach ($lines as $line) {
                $record = new CurrencyFxRevaluationLine;
                $record->forceFill([
                    'tenant_id' => $command->tenantId,
                    'revaluation_id' => $revaluation->id,
                    'line_type' => $line['line_type']->value,
                    'currency' => $line['currency'],
                    'reference' => $line['reference'],
                    'account_id' => $line['account_id'],
                    'currency_balance' => $line['currency_balance'],
                    'book_value' => $line['book_value'],
                    'closing_rate' => $line['closing_rate'],
                    'revalued_value' => $line['revalued_value'],
                    'difference' => $line['difference'],
                    'result' => $line['result']->value,
                    'treatment' => $line['treatment']->value,
                ]);
                $record->save();
            }

            return $revaluation->load('lines');
        });
    }

    private function closingRate(string $currency, string $functional, string $date, string $tenantId): ExchangeRateValue
    {
        $pair = CurrencyPair::of($currency, $functional);

        try {
            return $this->selector->select($pair, $date, $tenantId, RateType::Closing)->rate;
        } catch (ExchangeRateMissingException) {
            return $this->selector->select($pair, $date, $tenantId, RateType::Mid)->rate;
        }
    }

    private function assertPeriodEnd(CarbonImmutable $periodEnd, RevaluationPeriodType $type): void
    {
        $isMonthEnd = $periodEnd->isSameDay($periodEnd->endOfMonth());
        $valid = match ($type) {
            RevaluationPeriodType::Monthly, RevaluationPeriodType::Annual => $isMonthEnd,
            RevaluationPeriodType::Quarterly => $isMonthEnd && in_array($periodEnd->month, [3, 6, 9, 12], true),
        };

        if (! $valid) {
            throw new CurrencyDomainException(__('currency_fx.errors.period_end_invalid', ['type' => $type->value]), 'period_end_invalid', 422, 'period_end');
        }
    }
}
