<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\Projects\Events\ProjectCashFlowForecastEvent;
use Modules\Treasury\Contracts\CashForecastServiceInterface;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Models\CashForecast;

final readonly class ProjectsTreasuryListener
{
    public function __construct(
        private CashForecastServiceInterface $forecastService,
        private ReceivableCollectionServiceInterface $collectionService,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleCashFlowForecast(ProjectCashFlowForecastEvent $event): void
    {
        $forecast = CashForecast::where('tenant_id', $event->tenantId)->latest()->first()
            ?? $this->forecastService->generateForecast($event->tenantId);

        $netFlow = $event->netProjectCashFlow;

        $this->forecastService->recordForecastLine(
            cashForecastId: (string) $forecast->id,
            projectedDate: now()->addDays(30)->toDateString(),
            sourceCategory: 'project_forecast',
            flowType: $netFlow >= 0 ? 'inflow' : 'outflow',
            expectedAmount: abs($netFlow),
            confidenceWeight: 0.85,
            currency: 'EUR',
            sourceReference: "Project Cashflow {$event->projectId}",
            sourceEntityId: $event->projectId
        );
    }
}
