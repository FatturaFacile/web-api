<?php

declare(strict_types=1);

namespace Modules\Treasury\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\Projects\Events\ProjectBillingReadyEvent;
use Modules\Projects\Events\ProjectCashFlowForecastEvent;
use Modules\Projects\Events\ProjectCollectionForecastEvent;
use Modules\Projects\Events\ProjectPaymentForecastEvent;
use Modules\Projects\Events\ProjectReceivableReadyEvent;
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

    public function handleBillingReady(ProjectBillingReadyEvent $event): void
    {
        $this->collectionService->scheduleCollection([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'customer_id' => $event->customerId,
            'expected_amount' => $event->totalBilled,
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);
    }

    public function handleCollectionForecast(ProjectCollectionForecastEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        $this->auditLedger->record(
            action: 'project_collection_forecast_ingested',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'project_id' => $event->projectId,
                'forecast_amount' => $event->forecastedInflow,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handlePaymentForecast(ProjectPaymentForecastEvent $event): void
    {
        $account = BankAccount::where('tenant_id', $event->tenantId)->first()
            ?? new BankAccount(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenantId]);

        $this->auditLedger->record(
            action: 'project_payment_forecast_ingested',
            model: $account,
            payloadBefore: null,
            payloadAfter: [
                'project_id' => $event->projectId,
                'forecast_amount' => $event->forecastAmount,
            ],
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function handleReceivableReady(ProjectReceivableReadyEvent $event): void
    {
        $this->collectionService->scheduleCollection([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'customer_id' => $event->customerId,
            'expected_amount' => $event->receivableAmount,
            'due_date' => $event->dueDate,
            'payment_method' => 'bank_transfer',
        ]);
    }
}
