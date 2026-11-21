<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Commands\GeneratePredictiveInsightsCommand;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Infrastructure\Services\AnomalyGuardService;
use Modules\AiCognitive\Infrastructure\Services\PredictiveInsightsService;

final readonly class GeneratePredictiveInsightsHandler
{
    public function __construct(
        private AiTenantRunner $runner,
        private PredictiveInsightsService $insights,
        private AnomalyGuardService $anomalies,
        private AiAuditor $auditor,
    ) {}

    /**
     * @return array{cash_flow: int, late_payment: int, churn: int, reorder: int, anomalies: int}
     */
    public function handle(GeneratePredictiveInsightsCommand $command): array
    {
        return $this->runner->run($command->tenantId, function () use ($command): array {
            $counts = $this->insights->generate($command->tenantId, $command->userId) + ['anomalies' => 0];

            if ($command->withAnomalyScan) {
                $counts['anomalies'] = count($this->anomalies->scanTenant($command->tenantId));
            }

            $this->auditor->record($command->tenantId, 'insights_generated', $command->userId, null, null, null, null, null, $counts);

            return $counts;
        });
    }
}
