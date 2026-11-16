<?php

declare(strict_types=1);

namespace Modules\Logistics\Actions;

use Modules\Logistics\Enums\DiscrepancyStatus;
use Modules\Logistics\Models\TransferDiscrepancy;
use Modules\Logistics\Services\StockTransferService;

final class ResolveTransferDiscrepancyAction
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * Manager sign-off of a shortage/damage variance. When `$transferId` is given the discrepancy must belong to it.
     */
    public function execute(string $discrepancyId, DiscrepancyStatus $resolution, ?string $userId, ?string $transferId = null): TransferDiscrepancy
    {
        $discrepancy = TransferDiscrepancy::query()
            ->when($transferId !== null, fn ($q) => $q->where('stock_transfer_order_id', $transferId))
            ->findOrFail($discrepancyId);

        return $this->transfers->resolveDiscrepancy($discrepancy, $resolution, $userId);
    }
}
