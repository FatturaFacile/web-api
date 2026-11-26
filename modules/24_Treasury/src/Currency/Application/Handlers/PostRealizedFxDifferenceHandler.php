<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Treasury\Currency\Application\Commands\PostRealizedFxDifferenceCommand;
use Modules\Treasury\Currency\Domain\Enums\FxDirection;
use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Events\PaymentAllocated;
use Modules\Treasury\Currency\Domain\Events\RealizedFxDifferencePosted;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\Services\RealizedFxCalculator;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;
use Modules\Treasury\Currency\Infrastructure\Persistence\FxLedgerPoster;
use Modules\Treasury\Currency\Infrastructure\Persistence\RealizedFxDifference;

/**
 * Settles the exchange difference of a receipt or payment allocated to an open item, always against the rate frozen on the
 * document (a revaluation is reversed, so nothing is counted twice). The difference is recorded once per allocation; it is posted
 * to the ledger only when the settlement entry did not already carry it. A document in the functional currency has no difference.
 */
final readonly class PostRealizedFxDifferenceHandler
{
    public function __construct(
        private CurrencySettingsRepository $settings,
        private RateSelector $selector,
        private RealizedFxCalculator $calculator,
        private FxLedgerPoster $poster,
    ) {}

    public function handle(PostRealizedFxDifferenceCommand $command): ?RealizedFxDifference
    {
        $allocation = $command->allocation;
        $tenantId = $allocation->tenantId;
        $functional = $this->settings->effective($tenantId)->functionalCurrency;
        $currency = strtoupper($allocation->documentCurrency);

        if ($currency === $functional) {
            return null;
        }

        [$difference, $fresh] = DB::transaction(function () use ($allocation, $tenantId, $functional, $currency): array {
            TransactionLock::acquire('currency_realized_fx:'.$tenantId.':'.$allocation->allocationId);

            $existing = RealizedFxDifference::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('allocation_id', $allocation->allocationId)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $documentRate = ExchangeRateValue::of($allocation->documentRate);
            [$paymentRate, $outcome] = $this->calculate($allocation, $tenantId, $functional, $currency, $documentRate);

            $record = new RealizedFxDifference;
            $record->forceFill([
                'tenant_id' => $tenantId,
                'allocation_id' => $allocation->allocationId,
                'direction' => $allocation->direction->value,
                'currency' => $currency,
                'settled_amount' => Dec::round($allocation->settledAmount, 4),
                'document_rate' => $documentRate->value,
                'payment_rate' => $paymentRate,
                'document_value' => $outcome['document_value'],
                'payment_value' => $outcome['payment_value'],
                'difference' => $outcome['difference'],
                'effect' => $outcome['effect'],
                'result' => $outcome['result']->value,
                'payment_date' => CarbonImmutable::parse($allocation->paymentDate)->toDateString(),
                'document_reference' => $allocation->documentReference,
                'partner_id' => $allocation->partnerId,
                'ledger_posted' => $allocation->ledgerPostedDifference,
                'created_by' => $allocation->actorId,
            ]);
            $record->save();

            if (! $allocation->ledgerPostedDifference && $outcome['result'] !== FxResult::Neutral) {
                $record->forceFill(['journal_entry_id' => $this->post($record, $allocation, $functional)])->save();
            }

            return [$record, true];
        });

        if ($fresh) {
            Event::dispatch(new RealizedFxDifferencePosted($tenantId, (string) $difference->id, $allocation->allocationId, $currency, (string) $difference->effect, $difference->result->value, $difference->journal_entry_id));
        }

        return $difference;
    }

    /**
     * @return array{0: ?string, 1: array{difference: string, effect: string, result: FxResult, document_value: string, payment_value: string}}
     */
    private function calculate(PaymentAllocated $allocation, string $tenantId, string $functional, string $currency, ExchangeRateValue $documentRate): array
    {
        if ($allocation->paymentFunctionalAmount !== null) {
            return [null, $this->calculator->forCrossCurrencySettlement($allocation->direction, $allocation->settledAmount, $documentRate, $allocation->paymentFunctionalAmount)];
        }

        $paymentRate = $allocation->paymentRate !== null
            ? ExchangeRateValue::of($allocation->paymentRate)
            : $this->selector->select(CurrencyPair::of($currency, $functional), $allocation->paymentDate, $tenantId, RateType::Mid)->rate;

        $outcome = $allocation->itemAmount !== null && Dec::cmp($allocation->itemAmount, $allocation->settledAmount) !== 0 && ! Dec::isZero($allocation->itemAmount)
            ? $this->calculator->forPartialSettlement($allocation->direction, $allocation->itemAmount, $allocation->settledAmount, $documentRate, $paymentRate)
            : $this->calculator->forSettlement($allocation->direction, $allocation->settledAmount, $documentRate, $paymentRate);

        return [$paymentRate->value, $outcome];
    }

    /**
     * A gain: debit the control account (receivable or payable), credit the gain account; a loss the opposite.
     */
    private function post(RealizedFxDifference $record, PaymentAllocated $allocation, string $functional): string
    {
        $tenantId = (string) $record->tenant_id;
        $settings = $this->settings->effective($tenantId);
        $gain = $record->result === FxResult::Gain;
        $control = $this->poster->controlAccountId($tenantId, $allocation->direction === FxDirection::Receivable);
        $counter = $this->poster->counterAccountId($tenantId, $settings, $gain ? FxLedgerPoster::REALIZED_GAIN : FxLedgerPoster::REALIZED_LOSS);
        $amount = Dec::money(Dec::abs((string) $record->effect));
        $label = sprintf('Realised exchange %s: %s %s', $gain ? 'gain' : 'loss', $record->currency, $allocation->documentReference ?? $allocation->allocationId);
        $controlLine = ['account_id' => $control, 'currency' => $functional, 'description' => $label];
        $counterLine = ['account_id' => $counter, 'currency' => $functional, 'description' => $label];

        $lines = $gain
            ? [$controlLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $counterLine + ['debit_amount' => '0', 'credit_amount' => $amount]]
            : [$counterLine + ['debit_amount' => $amount, 'credit_amount' => '0'], $controlLine + ['debit_amount' => '0', 'credit_amount' => $amount]];

        return (string) $this->poster->post($tenantId, $record->payment_date->toDateString(), 'currency_realized_fx', (string) $record->id, $label, $lines, $allocation->actorId)->id;
    }
}
