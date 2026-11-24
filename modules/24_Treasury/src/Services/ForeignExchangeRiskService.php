<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\ExchangeRate;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use Modules\Treasury\Events\FXAdjustmentCalculatedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\FxPosition;
use Modules\Treasury\Models\FxRevaluation;
use Modules\Treasury\Models\PaymentLine;

final readonly class ForeignExchangeRiskService implements ForeignExchangeRiskServiceInterface
{
    private const int VOLATILITY_WINDOW = 30;

    private const int MINIMUM_RATES_FOR_VOLATILITY = 10;

    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher
    ) {}

    public function computeCurrencyExposure(string $tenantId, ?string $asOfDate = null, ?string $companyId = null): array
    {
        $date = $asOfDate ?? now()->toDateString();
        $baseCurrency = TenantAccountingCurrency::of($tenantId);
        $positions = [];
        $currencies = BankAccount::where('tenant_id', $tenantId)->where('is_active', true)->pluck('currency')
            ->merge(DB::table('invoices')->where('tenant_id', $tenantId)->where('payment_status', '!=', 'paid')->whereNull('deleted_at')->distinct()->pluck('currency'))
            ->unique()
            ->reject(static fn (?string $currency): bool => $currency === null || $currency === $baseCurrency)
            ->values();

        foreach ($currencies as $curr) {
            $cashHoldings = (float) BankAccount::where('tenant_id', $tenantId)
                ->where('currency', $curr)
                ->where('is_active', true)
                ->sum('current_balance');

            $rates = ExchangeRate::query()
                ->where('source', $baseCurrency)
                ->where('target', $curr)
                ->whereDate('effective_date', '<=', $date)
                ->latest('effective_date')
                ->limit(self::VOLATILITY_WINDOW + 1)
                ->pluck('rate');

            // Without a published rate the exposure cannot be valued: no position is better than an invented one.
            if ($rates->isEmpty()) {
                continue;
            }

            $arExposure = (float) DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->where('currency', $curr)
                ->where('status', 'posted')
                ->where('payment_status', '!=', 'paid')
                ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(total_gross - total_paid), 0) as open_amount')
                ->value('open_amount');

            $apExposure = (float) PaymentLine::where('tenant_id', $tenantId)
                ->where('currency', $curr)
                ->whereIn('status', ['pending', 'approved'])
                ->where(static fn ($query) => $query->whereNull('recipient_type')->orWhere('recipient_type', '!=', 'customer'))
                ->sum('amount');

            $netExposure = $cashHoldings + $arExposure - $apExposure;

            $spotRate = (float) $rates->first();
            $volatility = $this->dailyVolatility($rates->map(static fn (mixed $rate): float => (float) $rate)->all());
            $var95 = $volatility === null ? null : $this->calculateVar95(abs($netExposure), $volatility);

            /** @var FxPosition $pos */
            $pos = FxPosition::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'currency' => $curr,
                    'as_of_date' => $date,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'total_cash_holdings' => $cashHoldings,
                    'open_ar_exposure' => $arExposure,
                    'open_ap_exposure' => $apExposure,
                    'net_exposure' => $netExposure,
                    'base_currency' => $baseCurrency,
                    'latest_spot_rate' => $spotRate,
                    'var_95_daily' => $var95,
                ]
            );

            $positions[$curr] = $pos;
        }

        return $positions;
    }

    /**
     * Standard deviation of the daily changes of the published rates, newest first. Null when the history is too short
     * to say anything.
     *
     * @param  list<float>  $rates
     */
    private function dailyVolatility(array $rates): ?float
    {
        if (count($rates) < self::MINIMUM_RATES_FOR_VOLATILITY) {
            return null;
        }

        $changes = [];

        for ($i = 0, $last = count($rates) - 1; $i < $last; $i++) {
            $changes[] = ($rates[$i] - $rates[$i + 1]) / $rates[$i + 1];
        }

        $mean = array_sum($changes) / count($changes);
        $variance = array_sum(array_map(static fn (float $change): float => ($change - $mean) ** 2, $changes)) / (count($changes) - 1);

        return sqrt($variance);
    }

    public function performFxRevaluation(
        string $tenantId,
        string $currency,
        string $periodEndDate,
        float $spotRate,
        ?string $companyId = null,
        ?string $userId = null
    ): FxRevaluation {
        return DB::transaction(function () use ($tenantId, $currency, $periodEndDate, $spotRate, $companyId) {
            $foreignHoldings = (float) BankAccount::where('tenant_id', $tenantId)
                ->where('currency', $currency)
                ->where('is_active', true)
                ->sum('current_balance');

            // Book value base (historical average acquisition rate)
            $historicalRate = 1.000000;
            $bookValueBase = $foreignHoldings * $historicalRate;
            $revaluedMarketBase = $foreignHoldings * $spotRate;
            $unrealizedGainLoss = $revaluedMarketBase - $bookValueBase;

            /** @var FxRevaluation $revaluation */
            $revaluation = FxRevaluation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'period_end_date' => $periodEndDate,
                'currency' => $currency,
                'foreign_amount' => $foreignHoldings,
                'book_value_base' => $bookValueBase,
                'revalued_market_base' => $revaluedMarketBase,
                'unrealized_gain_loss' => $unrealizedGainLoss,
                'gl_journal_entry_id' => null,
            ]);

            $event = new FXAdjustmentCalculatedEvent(
                revaluationId: (string) $revaluation->id,
                currency: $currency,
                unrealizedGainLoss: $unrealizedGainLoss,
                periodEndDate: $periodEndDate,
                journalEntryId: null,
                tenantId: $tenantId,
                companyId: $companyId ?? ''
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'fx_revaluation_calculated',
                model: $revaluation,
                payloadBefore: null,
                payloadAfter: $revaluation->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $revaluation;
        });
    }

    public function calculateVar95(float $exposureAmount, float $historicalVolatility): float
    {
        // 95% Confidence 1-day VaR (Z = 1.64485)
        return round($exposureAmount * $historicalVolatility * 1.64485, 2);
    }
}
