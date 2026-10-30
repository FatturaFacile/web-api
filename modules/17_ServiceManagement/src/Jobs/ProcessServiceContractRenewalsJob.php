<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\Events\ServiceRevenueForecastEvent;
use Modules\ServiceManagement\Models\ServiceContract;

final class ProcessServiceContractRenewalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly int $advanceNoticeDays = 30
    ) {
        $this->afterCommit();
    }

    public function handle(EventDispatcher $dispatcher, AuditLedgerInterface $auditLedger): void
    {
        $query = ServiceContract::where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now()->addDays($this->advanceNoticeDays)->toDateString());

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $contracts = $query->get();

        foreach ($contracts as $contract) {
            $auditLedger->record(
                'SERVICE_CONTRACT_RENEWAL_WINDOW',
                $contract,
                null,
                [
                    'end_date' => $contract->end_date,
                    'contract_value' => $contract->contract_value,
                ],
                'MODULE_17_ESM'
            );

            $dispatcher->dispatch(new ServiceRevenueForecastEvent(
                forecastPeriod: now()->format('Y-m'),
                projectedServiceRevenue: (float) $contract->contract_value,
                contractedMrr: (float) ($contract->contract_value / 12.0),
                tenantId: $contract->tenant_id,
                companyId: $contract->company_id ?? $contract->tenant_id
            ));
        }
    }
}
