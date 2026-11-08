<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\AssetLifecycleServiceInterface;
use Modules\AssetManagement\DTOs\CapitalizeAssetDTO;
use Modules\AssetManagement\DTOs\RegisterAssetDTO;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Support\Money;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;

/**
 * Registers assets from upstream documents: capital lines of goods receipts, capitalizable inventory items and
 * conversions, and capitalizes the registered assets of a purchase order when its supplier invoice is matched. Each
 * event is processed once (transactional inbox). Without an asset category the tenant has to configure one first: no
 * category with invented rates is created.
 */
final readonly class AssetAcquisitionEventListener
{
    public function __construct(
        private AssetLifecycleServiceInterface $lifecycleService
    ) {}

    /**
     * Records the event; a committed order creates no asset yet.
     */
    public function handlePurchaseOrderCommitted(PurchaseOrderCommittedEvent $event): void
    {
        $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handlePurchaseOrderCommitted');
    }

    /**
     * Registers an asset for every accepted capital line of a goods receipt.
     */
    public function handleGoodsReceived(GoodsReceivedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleGoodsReceived')) {
            return;
        }

        foreach ($event->receivedLines as $line) {
            $cost = Money::round(Money::mul($this->value($line, 'unit_cost') ?? '0', $this->value($line, 'quantity_accepted') ?? '0'));

            if (! (bool) $this->value($line, 'is_capital_asset') || ! Money::isPositive($cost)) {
                continue;
            }

            $category = $this->defaultCategory($event->tenantId);
            if ($category === null) {
                continue;
            }

            $this->lifecycleService->registerAsset(new RegisterAssetDTO(
                tenantId: $event->tenantId,
                companyId: $event->companyId ?: $event->tenantId,
                name: (string) ($this->value($line, 'description') ?? "Capital asset from goods receipt {$event->receiptNumber}"),
                categoryId: (string) $category->id,
                acquisitionCost: $cost,
                acquisitionType: 'purchase',
                purchaseOrderId: $event->purchaseOrderId,
                purchaseReceiptId: $event->receiptId,
                serialNumber: $this->value($line, 'lot_number')
            ));
        }
    }

    /**
     * Registers the capitalizable items of an inventory valuation.
     */
    public function handleInventoryValuationReady(InventoryValuationReadyEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleInventoryValuationReady')) {
            return;
        }

        $items = $event->valuationSummary['capitalizable_items'] ?? [];
        $tenantId = $event->tenantId ?: $event->companyId;
        $category = is_array($items) && $items !== [] ? $this->defaultCategory($tenantId) : null;

        if ($category === null) {
            return;
        }

        foreach ($items as $item) {
            $cost = Money::round($item['valuation_cost'] ?? '0');
            if (! Money::isPositive($cost)) {
                continue;
            }

            $this->lifecycleService->registerAsset(new RegisterAssetDTO(
                tenantId: $tenantId,
                companyId: $event->companyId ?: $tenantId,
                name: (string) ($item['item_name'] ?? 'Inventory-converted fixed asset'),
                categoryId: (string) $category->id,
                acquisitionCost: $cost,
                acquisitionType: 'inventory_conversion',
                inventoryItemId: $item['stock_item_id'] ?? null,
                serialNumber: $item['serial_number'] ?? null
            ));
        }
    }

    /**
     * Registers the items of an inventory adjustment converting stock into assets.
     */
    public function handleInventoryAdjustmentPosted(InventoryAdjustmentPostedEvent $event): void
    {
        if (! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleInventoryAdjustmentPosted')) {
            return;
        }

        if (! in_array($event->reasonCode, ['ASSET_CONVERSION', 'CAPITALIZE_EQUIPMENT'], true)) {
            return;
        }

        $tenantId = $event->tenantId ?: $event->companyId;
        $category = $this->defaultCategory($tenantId);
        if ($category === null) {
            return;
        }

        foreach ($event->lines as $line) {
            $cost = Money::round(Money::abs($line['cost_impact'] ?? $event->totalCostImpact));
            if (! Money::isPositive($cost)) {
                continue;
            }

            $this->lifecycleService->registerAsset(new RegisterAssetDTO(
                tenantId: $tenantId,
                companyId: $event->companyId ?: $tenantId,
                name: (string) ($line['item_name'] ?? 'Serialized inventory asset conversion'),
                categoryId: (string) $category->id,
                acquisitionCost: $cost,
                acquisitionType: 'inventory_conversion',
                inventoryItemId: $line['item_id'] ?? null,
                serialNumber: $line['serial_number'] ?? null
            ));
        }
    }

    /**
     * Records the event; a write-off of stock is no asset event.
     */
    public function handleInventoryWriteOff(InventoryWriteOffEvent $event): void
    {
        $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handleInventoryWriteOff');
    }

    /**
     * Capitalizes, at their acquisition cost, the registered assets of the purchase order of a matched supplier invoice.
     */
    public function handlePurchaseLiabilityReady(PurchaseLiabilityReadyEvent $event): void
    {
        // A supplier invoice without a purchase order has no asset waiting for it.
        if ($event->purchaseOrderId === '' || ! $this->recordInboxIfNew($event, 'AssetAcquisitionEventListener::handlePurchaseLiabilityReady')) {
            return;
        }

        $assets = FixedAsset::where('tenant_id', $event->tenantId)
            ->where('purchase_order_id', $event->purchaseOrderId)
            ->where('status', 'registered')
            ->where('is_locked', false)
            ->get();

        foreach ($assets as $asset) {
            if (! Money::isPositive($asset->acquisition_cost)) {
                continue;
            }

            $this->lifecycleService->capitalizeAsset(new CapitalizeAssetDTO(
                assetId: (string) $asset->id,
                capitalizedCost: (string) $asset->acquisition_cost,
                salvageValue: (string) $asset->salvage_value,
                autoPostToGl: true
            ));
        }
    }

    /**
     * Optional value of a payload line (producers may add keys beyond the documented shape).
     *
     * @param  array<string, mixed>  $line
     */
    private function value(array $line, string $key): mixed
    {
        return $line[$key] ?? null;
    }

    /**
     * Records the event in the inbox of this consumer; false when it was already processed.
     */
    private function recordInboxIfNew(DomainEventInterface $event, string $consumerName): bool
    {
        $eventId = method_exists($event, 'getEventId') && (string) $event->getEventId() !== ''
            ? (string) $event->getEventId()
            : hash('sha256', $event->getEventName().'|'.json_encode($event->toPayload()));
        $messageId = $consumerName.':'.$eventId;

        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return false;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId() ?: $event->getCompanyId(),
            'message_id' => $messageId,
            'event_type' => $event->getEventName(),
            'payload_json' => $event->toPayload(),
            'status' => 'completed',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        return true;
    }

    /**
     * The category for automatically registered assets: the tenant's active category flagged as default in its
     * metadata, else its first active category; null (with a warning) when the tenant has none.
     */
    private function defaultCategory(string $tenantId): ?AssetCategory
    {
        $categories = AssetCategory::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('code')->get();
        $category = $categories->first(static fn (AssetCategory $candidate): bool => (bool) ($candidate->metadata['is_default'] ?? false)) ?? $categories->first();

        if ($category === null) {
            Log::warning('No active asset category: an upstream document was not registered as an asset.', ['tenant_id' => $tenantId]);
        }

        return $category;
    }
}
