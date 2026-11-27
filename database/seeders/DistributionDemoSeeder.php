<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\Distribution\Domain\Enums\AllocationMode;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Domain\Enums\ChannelType;
use Modules\Distribution\Domain\Enums\ReplenishmentPolicy;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Models\DistributionLane;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Models\SalesChannel;
use Modules\Distribution\Models\SalesChannelWarehouse;
use Modules\Distribution\Services\DrpPlanner;
use Modules\Distribution\Services\NetworkService;
use Modules\Distribution\Services\ReplenishmentEngine;
use Modules\Distribution\Services\TransferWorkflowService;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\WarehouseLocationService;

/**
 * Demo distribution network for the first company: a central hub, two points of sale, a 3PL, a consignment-out and a
 * consignment-in warehouse (profiles and lanes), replenishment rules of the four policies, opening stock, three transfers (draft,
 * in transit, received), five sales channels with their allocation rules, the first replenishment proposals and a DRP run.
 * Idempotent by warehouse code; does nothing without a company or a user. Names are demo content; every interface text comes
 * from the dictionary seeders.
 */
final class DistributionDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($user): void {
            if (Warehouse::query()->where('code', 'DRP-HUB')->exists()) {
                return;
            }

            $network = app(NetworkService::class);
            $locations = app(WarehouseLocationService::class);
            $hub = $this->warehouse('DRP-HUB', 'Central hub Milano', 'standard');
            $storeLugano = $this->warehouse('DRP-POS-LUG', 'Store Lugano', 'standard');
            $storeComo = $this->warehouse('DRP-POS-COM', 'Store Como', 'standard');
            $threePl = $this->warehouse('DRP-3PL', '3PL Logistics Chiasso', 'standard');
            $consignmentOut = $this->warehouse('DRP-CON-OUT', 'Consignment at Hotel Panorama', 'customer_consignment');
            $consignmentIn = $this->warehouse('DRP-CON-IN', 'Supplier consignment stock', 'vendor_consignment', 'vendor');

            $network->saveProfile($hub, ['role' => WarehouseRole::Internal->value]);
            $network->saveProfile($storeLugano, ['role' => WarehouseRole::RetailPos->value, 'parent_warehouse_id' => $hub->id]);
            $network->saveProfile($storeComo, ['role' => WarehouseRole::RetailPos->value, 'parent_warehouse_id' => $hub->id]);
            $network->saveProfile($threePl, ['role' => WarehouseRole::ThirdParty3pl->value, 'external_reference' => '3PL-CH-0042']);
            $network->saveProfile($consignmentOut, ['role' => WarehouseRole::ConsignmentOut->value, 'settlement_trigger' => 'monthly_report', 'billing_frequency' => 'monthly', 'max_consignment_days' => 90, 'is_insured' => true]);
            $network->saveProfile($consignmentIn, ['role' => WarehouseRole::ConsignmentIn->value, 'settlement_trigger' => 'on_consumption', 'billing_frequency' => 'monthly', 'max_consignment_days' => 120]);

            $bins = [];

            foreach ([$hub, $storeLugano, $storeComo, $threePl, $consignmentOut, $consignmentIn] as $warehouse) {
                $bins[$warehouse->code] = $locations->addBin($warehouse, ['zone_code' => 'STO', 'zone_type' => 'storage', 'aisle_code' => 'A1', 'rack_code' => 'R1', 'bin_code' => 'B1', 'barcode' => $warehouse->code.'-B1']);
            }

            $category = StockItemCategory::query()->firstOrCreate(['code' => 'CAT-DRP'], ['name' => 'Distribution demo', 'valuation_method' => 'fifo']);
            $espresso = $this->item($category, 'DRP-ESP-250', 'Espresso beans 250 g', '4.20');
            $capsule = $this->item($category, 'DRP-CAP-50', 'Coffee capsules box of 50', '9.50');
            $grinder = $this->item($category, 'DRP-GRD-01', 'Burr grinder', '68.00');

            foreach ([[$espresso, '400', '60', '30', '20'], [$capsule, '300', '40', '25', '15'], [$grinder, '40', '6', '3', '2']] as [$item, $hubQuantity, $lugano, $como, $threePlQuantity]) {
                $this->receive($bins['DRP-HUB'], $item, $hubQuantity);
                $this->receive($bins['DRP-POS-LUG'], $item, $lugano);
                $this->receive($bins['DRP-POS-COM'], $item, $como);
                $this->receive($bins['DRP-3PL'], $item, $threePlQuantity);
            }

            $this->receive($bins['DRP-CON-OUT'], $espresso, '50');
            $this->receive($bins['DRP-CON-IN'], $capsule, '80');

            foreach ([[$hub, $storeLugano, 2, 'road'], [$hub, $storeComo, 1, 'road'], [$storeComo, $storeLugano, 1, 'courier'], [$storeLugano, $storeComo, 1, 'courier'], [$hub, $consignmentOut, 3, 'road']] as [$source, $destination, $days, $mode]) {
                DistributionLane::query()->firstOrCreate(
                    ['source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'transport_mode' => $mode],
                    ['lead_time_days' => $days, 'lead_time_hours' => 0, 'cost_per_unit' => $mode === 'courier' ? '0.60' : '0.20'],
                );
            }

            $this->rule($storeLugano, $espresso, ReplenishmentPolicy::MinMax, $hub, ['safety_stock' => 20, 'reorder_point' => 40, 'max_stock' => 120, 'transfer_multiple' => 12, 'lead_time_days' => 2]);
            $this->rule($storeComo, $espresso, ReplenishmentPolicy::MinMax, $hub, ['safety_stock' => 10, 'reorder_point' => 20, 'max_stock' => 40, 'transfer_multiple' => 12, 'lead_time_days' => 1]);
            $this->rule($storeLugano, $capsule, ReplenishmentPolicy::ReorderPointFixedQty, $hub, ['safety_stock' => 10, 'reorder_point' => 30, 'fixed_quantity' => 50, 'lead_time_days' => 2]);
            $this->rule($storeComo, $capsule, ReplenishmentPolicy::PeriodicReview, $hub, ['safety_stock' => 8, 'max_stock' => 60, 'review_cycle_days' => 7, 'target_days_of_supply' => 21, 'lead_time_days' => 1]);
            $this->rule($storeLugano, $grinder, ReplenishmentPolicy::DemandDrivenDrp, $hub, ['safety_stock' => 4, 'min_transfer_qty' => 2, 'lead_time_days' => 2]);

            $workflow = app(TransferWorkflowService::class);
            $userId = (string) $user->getKey();

            $workflow->create($hub, $storeComo, [['stock_item_id' => (string) $espresso->id, 'quantity' => 24]], $userId, null, 'Weekly top-up (draft)');

            $transit = $workflow->create($hub, $storeLugano, [['stock_item_id' => (string) $capsule->id, 'quantity' => 100], ['stock_item_id' => (string) $grinder->id, 'quantity' => 6]], $userId, null, 'Spring campaign', true);
            $workflow->dispatch($transit, $userId, ['carrier' => 'Swiss Post Logistics', 'tracking_number' => 'CH-DRP-880011', 'ddt_reference' => 'DDT-DRP-0001']);

            $received = $workflow->create($hub, $storeLugano, [['stock_item_id' => (string) $espresso->id, 'quantity' => 48]], $userId, null, 'Replenishment (received)', true);
            $received = $workflow->dispatch($received, $userId, ['carrier' => 'Brinks Courier', 'tracking_number' => 'BR-DRP-5521', 'ddt_reference' => 'DDT-DRP-0002']);
            $workflow->receive($received, [], $userId, true);

            $this->channels($hub, $storeLugano, $storeComo, $threePl);

            $run = app(DrpPlanner::class)->run(BucketType::Weekly, 8, [], [], $userId);
            app(ReplenishmentEngine::class)->generate([], [], (string) $run->id);
        });
    }

    private function warehouse(string $code, string $name, string $type, string $owner = 'company'): Warehouse
    {
        return Warehouse::query()->firstOrCreate(['code' => $code], ['name' => $name, 'warehouse_type' => $type, 'legal_owner_type' => $owner, 'is_active' => true]);
    }

    private function item(StockItemCategory $category, string $sku, string $name, string $cost): StockItem
    {
        return StockItem::query()->firstOrCreate(['sku' => $sku], [
            'category_id' => $category->id, 'name' => $name, 'uom' => 'PCE', 'tracking_type' => 'none',
            'standard_cost' => $cost, 'current_moving_average_cost' => $cost, 'is_active' => true,
        ]);
    }

    private function receive(WarehouseBin $bin, StockItem $item, string $quantity): void
    {
        app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $item->id,
            sourceBinId: null,
            destinationBinId: (string) $bin->id,
            quantity: (float) $quantity,
            movementType: 'supplier_receipt',
            customUnitCost: (float) $item->current_moving_average_cost,
        ));
    }

    /**
     * @param  array<string, int|string>  $values
     */
    private function rule(Warehouse $warehouse, StockItem $item, ReplenishmentPolicy $policy, Warehouse $source, array $values): void
    {
        ReplenishmentRule::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'stock_item_id' => $item->id],
            ['policy' => $policy->value, 'source_warehouse_id' => $source->id] + $values,
        );
    }

    private function channels(Warehouse $hub, Warehouse $storeLugano, Warehouse $storeComo, Warehouse $threePl): void
    {
        $definitions = [
            ['B2B_PORTAL', 'B2B portal', ChannelType::B2bPortal, 10, [[$hub, AllocationMode::Percentage, ['allocation_percentage' => 40]]]],
            ['ECOMMERCE', 'E-commerce', ChannelType::Ecommerce, 20, [[$hub, AllocationMode::Percentage, ['allocation_percentage' => 60, 'include_in_transit' => true, 'safety_buffer_qty' => 5]], [$threePl, AllocationMode::SharedPool, ['exclude_3pl' => false]]]],
            ['RETAIL_POS', 'Retail points of sale', ChannelType::RetailPos, 30, [[$storeLugano, AllocationMode::SharedPool, []], [$storeComo, AllocationMode::SharedPool, []]]],
            ['MARKETPLACE', 'Marketplace', ChannelType::Marketplace, 40, [[$hub, AllocationMode::FixedReserve, ['fixed_reserve_qty' => 20]]]],
            ['DIRECT_SALES', 'Direct sales', ChannelType::DirectSales, 50, [[$hub, AllocationMode::PriorityRank, ['priority_rank' => 1]]]],
        ];

        foreach ($definitions as [$code, $name, $type, $priority, $rules]) {
            $channel = SalesChannel::query()->firstOrCreate(['code' => $code], ['name' => $name, 'channel_type' => $type->value, 'priority' => $priority, 'is_active' => true]);

            foreach ($rules as [$warehouse, $mode, $extra]) {
                SalesChannelWarehouse::query()->firstOrCreate(
                    ['sales_channel_id' => $channel->id, 'warehouse_id' => $warehouse->id],
                    ['allocation_mode' => $mode->value] + $extra,
                );
            }
        }
    }
}
