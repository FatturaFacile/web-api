<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Events\CashForecastGeneratedEvent;
use Modules\Treasury\Events\LiquidityAlertEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\CashForecastLine;
use Modules\Treasury\Models\ForecastScenario;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\ReceivableCollection;
use Modules\Treasury\Models\TreasuryAlert;

final readonly class CashForecastService implements CashForecastServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher
    ) {}

    public function generateForecast(
        string $tenantId,
        string $scenarioCode = 'BASELINE',
        int $horizonDays = 90,
        ?string $calculationDate = null,
        ?string $companyId = null
    ): CashForecast {
        $calcDate = $calculationDate ?? now()->toDateString();

        return DB::transaction(function () use ($tenantId, $scenarioCode, $horizonDays, $calcDate, $companyId) {
            $scenario = ForecastScenario::where('tenant_id', $tenantId)
                ->where('code', $scenarioCode)
                ->first();

            if (! $scenario) {
                $scenario = ForecastScenario::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'code' => $scenarioCode,
                    'name' => 'Standard Baseline Liquidity Scenario',
                    'is_baseline' => true,
                    'is_active' => true,
                ]);
            }

            // Calculate opening cash balance across active bank accounts
            $openingCash = (float) BankAccount::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->sum('current_balance');

            $forecastNumber = 'FCST-'.date('Ymd').'-'.strtoupper(Str::random(6));

            /** @var CashForecast $forecast */
            $forecast = CashForecast::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'forecast_scenario_id' => $scenario->id,
                'forecast_number' => $forecastNumber,
                'calculation_date' => $calcDate,
                'horizon_days' => $horizonDays,
                'opening_cash_balance' => $openingCash,
                'projected_inflows' => 0.00,
                'projected_outflows' => 0.00,
                'closing_cash_balance' => $openingCash,
                'min_liquidity_threshold' => 50000.00,
                'deficit_detected' => false,
            ]);

            // Synthesize standard operational forecast lines across horizon
            $endDate = date('Y-m-d', strtotime("{$calcDate} +{$horizonDays} days"));
            $this->seedOperationalForecastLines($forecast, $calcDate, $endDate);

            // Re-evaluate inflows, outflows and deficit
            $this->evaluateDeficits((string) $forecast->id);
            $forecast->refresh();

            $event = new CashForecastGeneratedEvent(
                cashForecastId: (string) $forecast->id,
                calculationDate: (string) $forecast->calculation_date,
                horizonDays: (int) $forecast->horizon_days,
                openingBalance: (float) $forecast->opening_cash_balance,
                projectedInflows: (float) $forecast->projected_inflows,
                projectedOutflows: (float) $forecast->projected_outflows,
                closingBalance: (float) $forecast->closing_cash_balance,
                deficitDetected: (bool) $forecast->deficit_detected,
                tenantId: (string) $forecast->tenant_id,
                companyId: (string) ($forecast->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'forecast_generated',
                model: $forecast,
                payloadBefore: null,
                payloadAfter: $forecast->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $forecast;
        });
    }

    public function recordForecastLine(
        string $cashForecastId,
        string $projectedDate,
        string $sourceCategory,
        string $flowType,
        float $expectedAmount,
        float $confidenceWeight = 1.00,
        string $currency = 'EUR',
        ?string $sourceReference = null,
        ?string $sourceEntityId = null
    ): void {
        $forecast = CashForecast::findOrFail($cashForecastId);
        $weighted = round($expectedAmount * $confidenceWeight, 2);

        CashForecastLine::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $forecast->tenant_id,
            'company_id' => $forecast->company_id,
            'cash_forecast_id' => $forecast->id,
            'projected_date' => $projectedDate,
            'source_category' => $sourceCategory,
            'flow_type' => $flowType,
            'confidence_weight' => $confidenceWeight,
            'expected_amount' => $expectedAmount,
            'weighted_amount' => $weighted,
            'currency' => $currency,
            'source_reference' => $sourceReference,
            'source_entity_id' => $sourceEntityId,
        ]);
    }

    public function evaluateDeficits(string $cashForecastId): array
    {
        /** @var CashForecast $forecast */
        $forecast = CashForecast::findOrFail($cashForecastId);

        $inflows = (float) CashForecastLine::where('cash_forecast_id', $forecast->id)
            ->where('flow_type', 'inflow')
            ->sum('weighted_amount');

        $outflows = (float) CashForecastLine::where('cash_forecast_id', $forecast->id)
            ->where('flow_type', 'outflow')
            ->sum('weighted_amount');

        $closingBalance = (float) $forecast->opening_cash_balance + $inflows - $outflows;
        $deficit = $closingBalance < (float) $forecast->min_liquidity_threshold;
        $deficitDate = null;

        if ($deficit) {
            $running = (float) $forecast->opening_cash_balance;
            $lines = CashForecastLine::where('cash_forecast_id', $forecast->id)
                ->orderBy('projected_date')
                ->get();

            foreach ($lines as $line) {
                if ($line->flow_type === 'inflow') {
                    $running += (float) $line->weighted_amount;
                } else {
                    $running -= (float) $line->weighted_amount;
                }

                if ($running < (float) $forecast->min_liquidity_threshold) {
                    $deficitDate = $line->projected_date;
                    break;
                }
            }

            // Create Treasury Alert
            $alert = TreasuryAlert::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $forecast->tenant_id,
                'company_id' => $forecast->company_id,
                'alert_type' => 'liquidity_deficit',
                'severity' => 'critical',
                'title' => "Liquidity Deficit Warning for Forecast [{$forecast->forecast_number}]",
                'message' => "Projected closing balance €{$closingBalance} falls below minimum threshold €{$forecast->min_liquidity_threshold}.",
                'threshold_value' => (float) $forecast->min_liquidity_threshold,
                'actual_value' => $closingBalance,
                'is_acknowledged' => false,
                'payload_json' => [
                    'forecast_id' => $forecast->id,
                    'deficit_date' => $deficitDate,
                    'projected_inflows' => $inflows,
                    'projected_outflows' => $outflows,
                ],
            ]);

            $alertEvent = new LiquidityAlertEvent(
                alertId: (string) $alert->id,
                alertType: 'liquidity_deficit',
                severity: 'critical',
                title: $alert->title,
                thresholdValue: (float) $forecast->min_liquidity_threshold,
                actualValue: $closingBalance,
                tenantId: (string) $forecast->tenant_id,
                companyId: (string) ($forecast->company_id ?? '')
            );
            $this->dispatcher->publish($alertEvent);
            event($alertEvent);
        }

        $forecast->update([
            'projected_inflows' => $inflows,
            'projected_outflows' => $outflows,
            'closing_cash_balance' => $closingBalance,
            'deficit_detected' => $deficit,
            'deficit_date' => $deficitDate,
        ]);

        return [
            'forecast_id' => $forecast->id,
            'opening_balance' => (float) $forecast->opening_cash_balance,
            'inflows' => $inflows,
            'outflows' => $outflows,
            'closing_balance' => $closingBalance,
            'deficit_detected' => $deficit,
            'deficit_date' => $deficitDate,
        ];
    }

    private function seedOperationalForecastLines(CashForecast $forecast, string $startDate, string $endDate): void
    {
        $openReceivables = ReceivableCollection::where('tenant_id', $forecast->tenant_id)
            ->whereIn(DB::raw('LOWER(status)'), ['pending', 'partial'])
            ->whereBetween('due_date', [$startDate, $endDate])
            ->get();

        foreach ($openReceivables as $rc) {
            $open = (float) $rc->expected_amount - (float) $rc->collected_amount;
            if ($open > 0) {
                $this->recordForecastLine(
                    cashForecastId: (string) $forecast->id,
                    projectedDate: (string) $rc->due_date,
                    sourceCategory: 'ar_invoices',
                    flowType: 'inflow',
                    expectedAmount: $open,
                    confidenceWeight: 1.00,
                    currency: 'EUR',
                    sourceReference: 'SCHEDULED-AR-'.substr((string) $rc->id, 0, 8),
                    sourceEntityId: (string) $rc->id
                );
            }
        }

        $openBatches = PaymentBatch::where('tenant_id', $forecast->tenant_id)
            ->whereIn(DB::raw('LOWER(status)'), ['approved', 'draft'])
            ->whereBetween('execution_date', [$startDate, $endDate])
            ->get();

        foreach ($openBatches as $pb) {
            $this->recordForecastLine(
                cashForecastId: (string) $forecast->id,
                projectedDate: (string) $pb->execution_date,
                sourceCategory: 'ap_procurement',
                flowType: 'outflow',
                expectedAmount: (float) $pb->total_amount,
                confidenceWeight: 1.00,
                currency: 'EUR',
                sourceReference: 'BATCH-'.$pb->batch_reference,
                sourceEntityId: (string) $pb->id
            );
        }
    }
}
