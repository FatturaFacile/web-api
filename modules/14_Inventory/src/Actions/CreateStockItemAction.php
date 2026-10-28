<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Modules\Inventory\DTOs\CreateStockItemDTO;
use Modules\Inventory\Events\InventoryItemCreatedEvent;
use Modules\Inventory\Models\StockItem;

final readonly class CreateStockItemAction
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher
    ) {}

    public function execute(CreateStockItemDTO $dto): StockItem
    {
        $item = StockItem::create([
            'category_id' => $dto->categoryId,
            'sku' => $dto->sku,
            'name' => $dto->name,
            'uom' => $dto->uom,
            'description' => $dto->description,
            'barcode_ean13' => $dto->barcodeEan13,
            'barcode_custom' => $dto->barcodeCustom,
            'tracking_type' => $dto->trackingType,
            'standard_cost' => $dto->standardCost,
            'moving_average_cost' => $dto->movingAverageCost,
            'weight_kg' => $dto->weightKg,
            'is_active' => $dto->isActive,
            'company_id' => $dto->companyId,
        ]);

        $this->eventDispatcher->dispatch(new InventoryItemCreatedEvent(
            itemId: (string) $item->id,
            sku: $item->sku,
            name: $item->name,
            companyId: (string) ($item->company_id ?? 'default'),
            userId: null
        ));

        return $item;
    }
}
