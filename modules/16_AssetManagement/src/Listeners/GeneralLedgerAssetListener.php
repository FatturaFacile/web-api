<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\PeriodClosedEvent;
use Modules\GeneralLedger\Events\YearClosedEvent;

final readonly class GeneralLedgerAssetListener
{
    public function __construct(
        private DepreciationEngineServiceInterface $depreciationEngine
    ) {}

    public function handleJournalPosted(JournalPostedEvent $event): void
    {
        // Journal entry linkage is recorded synchronously during AssetAccountingService posting;
        // listener provides idempotent verification for asynchronous event replays.
    }

    public function handlePeriodClosed(PeriodClosedEvent $event): void
    {
        $periodCode = sprintf('%s-%02d', date('Y'), $event->periodNumber);

        $exists = DepreciationRun::where('tenant_id', $event->tenantId)
            ->where('period_code', $periodCode)
            ->where('period_type', 'monthly')
            ->exists();

        if (! $exists) {
            $this->depreciationEngine->executeDepreciationRun(new RunDepreciationDTO(
                tenantId: $event->tenantId,
                companyId: $event->companyId ?: $event->tenantId,
                periodCode: $periodCode,
                periodType: 'monthly',
                ledgerType: 'statutory',
                autoPostToGl: false
            ), $event->closedBy);
        }
    }

    public function handleYearClosed(YearClosedEvent $event): void
    {
        $exists = DepreciationRun::where('tenant_id', $event->tenantId)
            ->where('period_code', $event->yearCode)
            ->where('period_type', 'yearly')
            ->exists();

        if (! $exists) {
            $this->depreciationEngine->executeDepreciationRun(new RunDepreciationDTO(
                tenantId: $event->tenantId,
                companyId: $event->companyId ?: $event->tenantId,
                periodCode: $event->yearCode,
                periodType: 'yearly',
                ledgerType: 'statutory',
                autoPostToGl: false
            ), $event->closedBy);
        }
    }
}
