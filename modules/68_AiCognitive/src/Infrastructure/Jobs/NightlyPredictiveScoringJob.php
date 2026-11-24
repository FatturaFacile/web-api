<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AiCognitive\Application\Commands\GeneratePredictiveInsightsCommand;
use Modules\AiCognitive\Application\Handlers\GeneratePredictiveInsightsHandler;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Infrastructure\Services\SuggestionService;

/**
 * Nightly per tenant: predictive recommendations and the anomaly scan of the last days, then the expiry of review items
 * nobody looked at within the retention of the queue. Runs after the forecasting models of 03:00-03:40.
 */
final class NightlyPredictiveScoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('ai');
    }

    public function handle(GeneratePredictiveInsightsHandler $handler, AiTenantRunner $runner, SuggestionService $suggestions): void
    {
        $handler->handle(new GeneratePredictiveInsightsCommand($this->tenantId));
        $runner->run($this->tenantId, static fn (): int => $suggestions->expireStale());
    }
}
