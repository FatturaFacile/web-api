<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use App\Exceptions\BusinessRuleException;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\WarehouseDefaultBinResolver;
use Modules\Wms\Contracts\WmsStockGateway;

/**
 * The WMS stock gateway backed by the inventory module: every task movement is a double-entry transfer between two
 * bins, tagged with the task as origin document, which is also what makes the posting idempotent.
 */
final readonly class InventoryWmsStockGateway implements WmsStockGateway
{
    public const string OriginType = 'wms_task';

    public function __construct(
        private DoubleEntryMovementServiceInterface $movements,
        private WarehouseDefaultBinResolver $defaultBins,
    ) {}

    public function transfer(string $taskId, string $stockItemId, string $sourceBinId, string $destinationBinId, string $quantity, ?string $userId): string
    {
        $existing = StockMovement::query()
            ->where('origin_document_type', self::OriginType)
            ->where('origin_document_id', $taskId)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        try {
            $movement = $this->movements->executeTransfer(new StockTransferDTO(
                stockItemId: $stockItemId,
                sourceBinId: $sourceBinId,
                destinationBinId: $destinationBinId,
                quantity: (float) $quantity,
                movementType: 'INTERNAL_TRANSFER',
                originDocumentType: self::OriginType,
                originDocumentId: $taskId,
                userId: $userId,
                notes: 'WMS task',
            ));
        } catch (StockRuleViolation $violation) {
            throw new BusinessRuleException($violation->getMessage());
        }

        return (string) $movement->id;
    }

    public function receivingBinId(string $warehouseId): string
    {
        return (string) $this->defaultBins->resolve($warehouseId)->id;
    }
}
