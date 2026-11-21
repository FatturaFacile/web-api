<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\ValueObjects\SuggestionProposal;
use Modules\Forecasting\Application\Handlers\GetAiCashFlowConfidenceBandsHandler;
use Modules\Forecasting\Application\Queries\GetAiCashFlowConfidenceBandsQuery;
use Modules\Forecasting\Infrastructure\Models\InvoiceExpectedCollection;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;

/**
 * Turns the statistical models of the Forecasting module and the stock rules into actionable recommendations of the review
 * queue: a cash trough in the 30/60/90 day projection, customers whose payment behaviour deteriorated (dunning), customers
 * whose order frequency dropped (retention) and items at or below their reorder point. The numbers come from the models, not
 * from a language model, so every recommendation can be traced to its data.
 */
final readonly class PredictiveInsightsService
{
    public function __construct(
        private SuggestionService $suggestions,
        private GetAiCashFlowConfidenceBandsHandler $bands,
    ) {}

    /**
     * @return array{cash_flow: int, late_payment: int, churn: int, reorder: int}
     */
    public function generate(string $tenantId, ?string $userId = null): array
    {
        return [
            'cash_flow' => $this->cashFlow($tenantId, $userId),
            'late_payment' => $this->latePayment($tenantId, $userId),
            'churn' => $this->churn($tenantId, $userId),
            'reorder' => $this->reorder($tenantId, $userId),
        ];
    }

    private function cashFlow(string $tenantId, ?string $userId): int
    {
        $series = $this->bands->handle(new GetAiCashFlowConfidenceBandsQuery($tenantId));

        if ($series === null) {
            return 0;
        }

        foreach ($series['p10'] as $index => $balance) {
            if ((float) $balance < 0.0) {
                $date = (string) $series['dates'][$index];
                $this->suggestions->propose(new SuggestionProposal(
                    $tenantId,
                    SuggestionKind::CashFlowAlert,
                    'system',
                    ['first_negative_date' => $date, 'p10_balance' => (float) $balance, 'p50_balance_at_date' => (float) ($series['p50'][$index] ?? 0), 'horizon_days' => $series['horizon_days'], 'currency' => $series['currency'], 'run_id' => $series['run_id']],
                    0.8,
                    'cash_flow:'.$series['run_id'],
                    null,
                    [],
                    null,
                    "In the pessimistic (10th percentile) projection the cash balance turns negative on {$date}.",
                    [['type' => 'liquidity_forecast_run', 'reference' => $series['run_id']]],
                    'high',
                    null,
                    null,
                    $userId,
                ));

                return 1;
            }
        }

        return 0;
    }

    private function latePayment(string $tenantId, ?string $userId): int
    {
        $count = 0;
        $open = InvoiceExpectedCollection::query()->where('tenant_id', $tenantId)->get()->groupBy('customer_id');

        foreach (PaymentBehaviorModel::query()->where('tenant_id', $tenantId)->get() as $model) {
            if ($model->payment_risk_tier->severity() < 2) {
                continue;
            }

            $exposure = round((float) ($open->get($model->customer_id) ?? collect())->sum(fn (InvoiceExpectedCollection $row): float => (float) $row->open_amount), 2);

            $this->suggestions->propose(new SuggestionProposal(
                $tenantId,
                SuggestionKind::LatePaymentRisk,
                'system',
                [
                    'customer_id' => $model->customer_id,
                    'risk_tier' => $model->payment_risk_tier->value,
                    'average_days_late' => (float) $model->average_days_late,
                    'on_time_probability' => (float) $model->on_time_payment_probability,
                    'open_amount' => $exposure,
                    'recommended_action' => 'send_payment_reminder',
                ],
                round(min(0.95, 0.6 + $model->sample_size * 0.02), 2),
                'late_payment:'.$model->customer_id,
                null,
                [],
                $exposure,
                'The customer pays on average '.round((float) $model->average_days_late, 1).' days late and is on time in '.round((float) $model->on_time_payment_probability * 100).'% of the cases: start a reminder early.',
                [['type' => 'customer_payment_behavior', 'reference' => $model->id]],
                $model->payment_risk_tier->severity() >= 3 ? 'high' : 'normal',
                null,
                null,
                $userId,
            ));
            $count++;
        }

        return $count;
    }

    private function churn(string $tenantId, ?string $userId): int
    {
        $count = 0;
        $recentFrom = now()->subDays(90)->toDateString();
        $priorFrom = now()->subDays(180)->toDateString();

        $rows = DB::table('invoices')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('date', '>=', $priorFrom)
            ->get(['contact_id', 'date', 'total_gross']);

        $stats = [];

        foreach ($rows as $row) {
            $key = $row->date >= $recentFrom ? 'recent' : 'prior';
            $stats[$row->contact_id][$key] = ($stats[$row->contact_id][$key] ?? 0) + 1;
            $stats[$row->contact_id][$key.'_revenue'] = ($stats[$row->contact_id][$key.'_revenue'] ?? 0.0) + (float) $row->total_gross;
        }

        foreach ($stats as $customerId => $stat) {
            $prior = (int) ($stat['prior'] ?? 0);
            $recent = (int) ($stat['recent'] ?? 0);

            // A regular customer (three or more orders in the earlier quarter) who dropped to 40% or less of that.
            if ($prior < 3 || $recent > $prior * 0.4) {
                continue;
            }

            $this->suggestions->propose(new SuggestionProposal(
                $tenantId,
                SuggestionKind::ChurnRisk,
                'system',
                ['customer_id' => (string) $customerId, 'invoices_prior_90_days' => $prior, 'invoices_last_90_days' => $recent, 'revenue_prior' => round((float) ($stat['prior_revenue'] ?? 0), 2), 'revenue_recent' => round((float) ($stat['recent_revenue'] ?? 0), 2), 'recommended_action' => 'contact_customer'],
                round(min(0.9, 0.55 + ($prior - $recent) * 0.05), 2),
                'churn:'.$customerId,
                null,
                [],
                round((float) ($stat['prior_revenue'] ?? 0), 2),
                "The order frequency fell from {$prior} to {$recent} invoices in the last 90 days: contact the customer.",
                [['type' => 'customer', 'reference' => (string) $customerId]],
                'normal',
                null,
                null,
                $userId,
            ));
            $count++;
        }

        return $count;
    }

    private function reorder(string $tenantId, ?string $userId): int
    {
        $count = 0;

        $rules = DB::table('stock_reorder_rules')
            ->join('stock_items', 'stock_items.id', '=', 'stock_reorder_rules.stock_item_id')
            ->where('stock_reorder_rules.tenant_id', $tenantId)->whereNull('stock_reorder_rules.deleted_at')->whereNull('stock_items.deleted_at')
            ->groupBy('stock_items.id', 'stock_items.sku', 'stock_items.name')
            ->get(['stock_items.id', 'stock_items.sku', 'stock_items.name', DB::raw('SUM(stock_reorder_rules.reorder_point) as reorder_point'), DB::raw('SUM(stock_reorder_rules.economic_order_quantity) as order_quantity'), DB::raw('MAX(stock_reorder_rules.lead_time_days) as lead_time_days')]);

        $available = DB::table('stock_quants')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->groupBy('stock_item_id')->selectRaw('stock_item_id, SUM(quantity_on_hand - quantity_reserved) as available')->pluck('available', 'stock_item_id');

        foreach ($rules as $rule) {
            $onHand = (float) ($available[$rule->id] ?? 0);

            if ($onHand > (float) $rule->reorder_point) {
                continue;
            }

            $this->suggestions->propose(new SuggestionProposal(
                $tenantId,
                SuggestionKind::Reorder,
                'system',
                ['stock_item_id' => $rule->id, 'sku' => $rule->sku, 'name' => $rule->name, 'available' => $onHand, 'reorder_point' => (float) $rule->reorder_point, 'suggested_quantity' => (float) $rule->order_quantity, 'lead_time_days' => (int) $rule->lead_time_days, 'recommended_action' => 'create_purchase_requisition'],
                0.85,
                'reorder:'.$rule->id,
                null,
                [],
                null,
                "Available stock {$onHand} is at or below the reorder point {$rule->reorder_point}; the supplier lead time is {$rule->lead_time_days} days.",
                [['type' => 'stock_item', 'reference' => (string) $rule->id]],
                $onHand <= 0 ? 'high' : 'normal',
                null,
                null,
                $userId,
            ));
            $count++;
        }

        return $count;
    }
}
