<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use Modules\Treasury\Events\FXAdjustmentCalculatedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\FxPosition;
use Modules\Treasury\Models\FxRevaluation;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\ReceivableCollection;

final readonly class ForeignExchangeRiskService implements ForeignExchangeRiskServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher
    ) {}

    public function computeCurrencyExposure(string $tenantId, ?string $asOfDate = null, ?string $companyId = null): array
    {
        $date = $asOfDate ?? now()->toDateString();
        $currencies = ['USD', 'CHF', 'GBP']; // Foreign currencies vs base EUR
        $positions = [];

        foreach ($currencies as $curr) {
            $cashHoldings = (float) BankAccount::where('tenant_id', $tenantId)
                ->where('currency', $curr)
                ->where('is_active', true)
                ->sum('current_balance');

            $arExposure = (float) ReceivableCollection::where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'partial'])
                ->sum('expected_amount') * 0.15; // Estimated 15% foreign AR

            $apExposure = (float) PaymentLine::where('tenant_id', $tenantId)
                ->where('currency', $curr)
                ->whereIn('status', ['pending', 'approved'])
                ->sum('amount');

            $netExposure = $cashHoldings + $arExposure - $apExposure;

            $spotRate = match ($curr) {
                'USD' => 1.085000,
                'CHF' => 0.955000,
                'GBP' => 0.855000,
                default => 1.000000,
            };

            $var95 = $this->calculateVar95(abs($netExposure), 0.012); // 1.2% daily vol

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
                    'base_currency' => 'EUR',
                    'latest_spot_rate' => $spotRate,
                    'var_95_daily' => $var95,
                ]
            );

            $positions[$curr] = $pos;
        }

        return $positions;
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
