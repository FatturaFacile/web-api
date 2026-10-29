<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;

final readonly class AssetAcquisitionEventListener
{
    public function __construct(
        private AssetLifecycleServiceInterface $lifecycleService
    ) {}

    public function handlePurchaseOrderCommitted(PurchaseOrderCommittedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handlePurchaseOrderCommitted')) {
            return;
        }
    }

    public function handleGoodsReceived(GoodsReceivedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleGoodsReceived')) {
            return;
        }

        foreach ($event->receivedLines as $line) {
            $isCapitalAsset = (bool) ($line['is_capital_asset'] ?? false);
            $unitCost = (float) ($line['unit_cost'] ?? 0.0);
            $qtyAccepted = (float) ($line['quantity_accepted'] ?? 0.0);

            if (! $isCapitalAsset || $qtyAccepted <= 0 || $unitCost <= 0) {
                continue;
            }

            $category = $this->resolveDefaultCategory($event->tenantId);

            $this->lifecycleService->registerAsset(new RegisterAssetDTO(
                tenantId: $event->tenantId,
                companyId: $event->companyId ?: $event->tenantId,
                name: $line['description'] ?? "Capital Asset from GRN {$event->receiptNumber}",
                categoryId: (string) $category->id,
                acquisitionCost: round($unitCost * $qtyAccepted, 2),
                acquisitionType: 'purchase',
                purchaseOrderId: $event->purchaseOrderId,
                purchaseReceiptId: $event->receiptId,
                serialNumber: $line['lot_number'] ?? null
            ));
        }
    }

    public function handleInventoryValuationReady(InventoryValuationReadyEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleInventoryValuationReady')) {
            return;
        }

        $capitalizableItems = $event->valuationSummary['capitalizable_items'] ?? [];
        if (empty($capitalizableItems) || ! is_array($capitalizableItems)) {
            return;
        }

        $tenantId = $event->tenantId ?: $event->companyId;
        $category = $this->resolveDefaultCategory($tenantId);

        foreach ($capitalizableItems as $item) {
            $cost = (float) ($item['valuation_cost'] ?? 0.0);
            if ($cost <= 0.0) {
                continue;
            }

            $this->lifecycleService->registerAsset(new RegisterAssetDTO(
                tenantId: $tenantId,
                companyId: $event->companyId ?: $tenantId,
                name: $item['item_name'] ?? 'Inventory-Converted Fixed Asset',
                categoryId: (string) $category->id,
                acquisitionCost: $cost,
                acquisitionType: 'inventory_conversion',
                inventoryItemId: $item['stock_item_id'] ?? null,
                serialNumber: $item['serial_number'] ?? null
            ));
        }
    }

    public function handleInventoryAdjustmentPosted(InventoryAdjustmentPostedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleInventoryAdjustmentPosted')) {
            return;
        }

        if (! in_array($event->reasonCode, ['ASSET_CONVERSION', 'CAPITALIZE_EQUIPMENT'], true)) {
            return;
        }

        $tenantId = $event->tenantId ?: $event->companyId;
        $category = $this->resolveDefaultCategory($tenantId);

        foreach ($event->lines as $line) {
            $cost = abs((float) ($line['cost_impact'] ?? $event->totalCostImpact));
            if ($cost <= 0.0) {
                continue;
            }

            $this->lifecycleService->registerAsset(new RegisterAssetDTO(
                tenantId: $tenantId,
                companyId: $event->companyId ?: $tenantId,
                name: $line['item_name'] ?? 'Serialized Inventory Asset Conversion',
                categoryId: (string) $category->id,
                acquisitionCost: $cost,
                acquisitionType: 'inventory_conversion',
                inventoryItemId: $line['item_id'] ?? null,
                serialNumber: $line['serial_number'] ?? null
            ));
        }
    }

    public function handleInventoryWriteOff(InventoryWriteOffEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleInventoryWriteOff')) {
            return;
        }
    }

    public function handlePurchaseLiabilityReady(PurchaseLiabilityReadyEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handlePurchaseLiabilityReady')) {
            return;
        }

        $assets = FixedAsset::where('tenant_id', $event->tenantId)
            ->where('purchase_order_id', $event->purchaseOrderId)
            ->where('status', 'registered')
            ->get();

        foreach ($assets as $asset) {
            $cost = (float) $asset->acquisition_cost > 0 ? (float) $asset->acquisition_cost : $event->netAmount;
            $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
                assetId: (string) $asset->id,
                capitalizedCost: $cost,
                salvageValue: (float) $asset->salvage_value,
                autoPostToGl: true
            ));
        }
    }

    private function recordInboxIfNew(DomainEventInterface $event, string $consumerName): bool
    {
        try {
            $eventId = $event->getEventId();
            $exists = TransactionalInbox::where('event_id', $eventId)
                ->where('consumer_name', $consumerName)
                ->exists();

            if ($exists) {
                return false;
            }

            TransactionalInbox::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $event->getTenantId() ?: $event->getCompanyId(),
                'event_id' => $eventId,
                'event_name' => $event->getEventName(),
                'consumer_name' => $consumerName,
                'payload_json' => $event->toPayload(),
                'status' => 'processed',
                'received_at' => now(),
                'processed_at' => now(),
            ]);

            return true;
        } catch (\Throwable) {
            return true;
        }
    }

    private function resolveDefaultCategory(string $tenantId): AssetCategory
    {
        $category = AssetCategory::where('tenant_id', $tenantId)->first();
        if ($category !== null) {
            return $category;
        }

        return AssetCategory::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => 'MACHINERY',
            'name' => 'Plant & Machinery',
            'asset_type' => 'tangible',
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 5,
            'useful_life_months' => 60,
            'statutory_rate_percent' => 20.00,
            'salvage_value_percent' => 5.00,
            'is_depreciable' => true,
            'is_active' => true,
        ]);
    }
}
