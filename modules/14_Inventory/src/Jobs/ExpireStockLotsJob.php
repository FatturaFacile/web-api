<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Inventory\Events\LotExpiredEvent;
use Modules\Inventory\Models\StockLot;

/**
 * Announces the lots whose expiration date has passed (LotExpiredEvent); its listener quarantines the lot and blocks its
 * stock. A quarantined lot is not announced again, so the job can run as often as needed.
 */
final class ExpireStockLotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly ?string $asOfDate = null
    ) {
        $this->afterCommit();
    }

    public function handle(EventBusInterface $eventBus): void
    {
        $today = $this->asOfDate ?? now()->toDateString();

        StockLot::withoutGlobalScopes()
            ->when($this->tenantId !== null, fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '<', $today)
            ->where('is_quarantined', false)
            ->chunkById(200, function ($lots) use ($eventBus): void {
                foreach ($lots as $lot) {
                    $eventBus->publishAndDispatch(new LotExpiredEvent(
                        lotId: (string) $lot->id,
                        lotNumber: (string) $lot->lot_number,
                        stockItemId: (string) $lot->stock_item_id,
                        expiryDate: $lot->expiration_date->toDateString(),
                        companyId: (string) ($lot->company_id ?? ''),
                        tenantId: (string) $lot->tenant_id,
                        eventId: (string) Str::uuid7(),
                        occurredAt: now()->toIso8601String(),
                    ));
                }
            });
    }
}
