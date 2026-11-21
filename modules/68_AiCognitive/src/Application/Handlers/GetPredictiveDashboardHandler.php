<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Queries\GetPredictiveDashboardQuery;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\Forecasting\Application\Handlers\GetAiCashFlowConfidenceBandsHandler;
use Modules\Forecasting\Application\Handlers\ListPaymentBehaviorModelsHandler;
use Modules\Forecasting\Application\Queries\GetAiCashFlowConfidenceBandsQuery;

/**
 * The Predictive Insights and Anomaly Center in one call: the cash flow projection with its confidence bands (from the
 * Forecasting module), the customers by payment risk, and the open recommendations and anomaly alerts of the review queue.
 */
final readonly class GetPredictiveDashboardHandler
{
    public function __construct(
        private AiTenantRunner $runner,
        private GetAiCashFlowConfidenceBandsHandler $bands,
        private ListPaymentBehaviorModelsHandler $payments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetPredictiveDashboardQuery $query): array
    {
        return $this->runner->run($query->tenantId, function () use ($query): array {
            $open = array_map(static fn (SuggestionStatus $status): string => $status->value, SuggestionStatus::open());
            $recommendations = [SuggestionKind::CashFlowAlert, SuggestionKind::LatePaymentRisk, SuggestionKind::ChurnRisk, SuggestionKind::Reorder];

            $items = AiSuggestion::query()->where('tenant_id', $query->tenantId)->whereIn('status', $open)
                ->whereIn('kind', array_map(static fn (SuggestionKind $kind): string => $kind->value, [...$recommendations, SuggestionKind::Anomaly]))
                ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")->orderByDesc('created_at')->limit(200)->get();

            return [
                'cash_flow' => $this->bands->handle(new GetAiCashFlowConfidenceBandsQuery($query->tenantId, null, $query->horizonDays)),
                'payment_risk' => array_slice($this->payments->handle($query->tenantId), 0, 10),
                'recommendations' => $items->filter(static fn (AiSuggestion $item): bool => $item->kind !== SuggestionKind::Anomaly)->values()->all(),
                'anomalies' => $items->filter(static fn (AiSuggestion $item): bool => $item->kind === SuggestionKind::Anomaly)->values()->all(),
            ];
        });
    }
}
