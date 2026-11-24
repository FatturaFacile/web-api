<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Copies the app's former production tables (`bills_of_materials`, `bom_items`, `production_orders`,
 * `production_order_stages`) into the manufacturing module, keyed on the legacy ids: every copy keeps the id of its
 * source row, so running it again copies only what is still missing. Legacy rows are never changed or deleted; rows
 * without a tenant are skipped.
 *
 * Catalog products become manufacturing items (linked by product and to the product's stock item), stages become
 * operations on a per-tenant `LEGACY` work centre with their planned and actual hours.
 */
final class LegacyProductionOrderMigrator
{
    /** Status of a legacy order => status of the module order. */
    private const array OrderStatuses = ['planned' => 'planned', 'in_progress' => 'in_progress', 'completed' => 'completed', 'cancelled' => 'cancelled'];

    /** Status of a legacy stage => status of the module operation. */
    private const array StageStatuses = ['pending' => 'pending', 'running' => 'in_progress', 'done' => 'completed'];

    /** @var array<string, string> tenant|product => item id */
    private array $items = [];

    /** @var array<string, string> tenant => work centre id */
    private array $workCenters = [];

    /**
     * Copies what is missing; returns the number of rows copied per kind.
     *
     * @return array{boms: int, components: int, orders: int, operations: int, items: int}
     */
    public function migrate(): array
    {
        $counts = ['boms' => 0, 'components' => 0, 'orders' => 0, 'operations' => 0, 'items' => 0];
        if (! Schema::hasTable('bills_of_materials') || ! Schema::hasTable('mfg_boms')) {
            return $counts;
        }

        DB::transaction(function () use (&$counts): void {
            foreach (DB::table('bills_of_materials')->whereNotNull('tenant_id')->orderBy('id')->get() as $bom) {
                $this->migrateBom($bom, $counts);
            }

            if (Schema::hasTable('production_orders')) {
                foreach (DB::table('production_orders')->whereNotNull('tenant_id')->orderBy('id')->get() as $order) {
                    $this->migrateOrder($order, $counts);
                }
            }
        });

        return $counts;
    }

    /**
     * @param  array{boms: int, components: int, orders: int, operations: int, items: int}  $counts
     */
    private function migrateBom(object $bom, array &$counts): void
    {
        $tenantId = (string) $bom->tenant_id;
        $itemId = $this->item($tenantId, (string) $bom->finished_product_id, 'finished_good', $counts);
        if ($itemId === null) {
            return;
        }

        if (! DB::table('mfg_boms')->where('id', $bom->id)->exists()) {
            DB::table('mfg_boms')->insert([
                'id' => $bom->id,
                'tenant_id' => $tenantId,
                'bom_number' => 'LEG-'.strtoupper(substr(str_replace('-', '', (string) $bom->id), 0, 16)),
                'mfg_item_id' => $itemId,
                'name' => Str::limit((string) $bom->name, 200, ''),
                'revision' => Str::limit((string) ($bom->version ?? '1.0'), 20, ''),
                'version' => 1,
                'bom_type' => 'standard',
                'status' => $bom->is_active ? 'active' : 'obsolete',
                'base_quantity' => 1,
                'uom' => 'PCS',
                'effective_from' => substr((string) ($bom->created_at ?? now()->toDateString()), 0, 10),
                'is_locked' => false,
                'notes' => trim('Migrated from the legacy bill of materials. '.($bom->description ?? '')),
                'created_at' => $bom->created_at,
                'updated_at' => $bom->updated_at,
                'deleted_at' => $bom->deleted_at,
            ]);
            $counts['boms']++;

            if ($bom->is_active && $bom->deleted_at === null) {
                DB::table('mfg_items')->where('id', $itemId)->whereNull('default_bom_id')->update(['default_bom_id' => $bom->id]);
            }
        }

        $sequence = 10;
        foreach (DB::table('bom_items')->where('bill_of_materials_id', $bom->id)->orderBy('created_at')->orderBy('id')->get() as $component) {
            if (! DB::table('mfg_bom_components')->where('id', $component->id)->exists()) {
                $componentItemId = $this->item($tenantId, (string) $component->raw_product_id, 'raw_material', $counts);
                if ($componentItemId !== null) {
                    DB::table('mfg_bom_components')->insert([
                        'id' => $component->id,
                        'tenant_id' => $tenantId,
                        'bom_id' => $bom->id,
                        'component_item_id' => $componentItemId,
                        'sequence' => $sequence,
                        'quantity_per' => $component->quantity,
                        'uom' => 'PCS',
                        'scrap_percentage' => $component->scrap_percentage ?? 0,
                        'yield_percentage' => 100,
                        'is_phantom' => false,
                        'issue_method' => 'manual',
                        'created_at' => $component->created_at,
                        'updated_at' => $component->updated_at,
                    ]);
                    $counts['components']++;
                }
            }
            $sequence += 10;
        }
    }

    /**
     * @param  array{boms: int, components: int, orders: int, operations: int, items: int}  $counts
     */
    private function migrateOrder(object $order, array &$counts): void
    {
        $tenantId = (string) $order->tenant_id;
        $bom = DB::table('mfg_boms')->where('id', $order->bill_of_materials_id)->first();
        if ($bom === null) {
            return;
        }

        if (! DB::table('mfg_production_orders')->where('id', $order->id)->exists()) {
            $number = (string) $order->order_number;
            if (DB::table('mfg_production_orders')->where('tenant_id', $tenantId)->where('order_number', $number)->exists()) {
                $number = Str::limit('LEG-'.$number, 50, '');
            }

            DB::table('mfg_production_orders')->insert([
                'id' => $order->id,
                'tenant_id' => $tenantId,
                'order_number' => $number,
                'mfg_item_id' => $bom->mfg_item_id,
                'bom_id' => $bom->id,
                'status' => self::OrderStatuses[(string) $order->status] ?? 'planned',
                'target_quantity' => $order->target_quantity,
                'completed_quantity' => $order->produced_quantity ?? 0,
                'uom' => 'PCS',
                'planned_start_date' => $order->start_date,
                'planned_end_date' => $order->due_date ?? $order->start_date,
                'notes' => 'Migrated from the legacy production order.',
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'deleted_at' => $order->deleted_at,
            ]);
            $counts['orders']++;
        }

        if (! Schema::hasTable('production_order_stages')) {
            return;
        }

        foreach (DB::table('production_order_stages')->where('production_order_id', $order->id)->orderBy('sequence')->get() as $stage) {
            if (DB::table('mfg_production_order_operations')->where('id', $stage->id)->exists()) {
                continue;
            }

            DB::table('mfg_production_order_operations')->insert([
                'id' => $stage->id,
                'tenant_id' => $tenantId,
                'production_order_id' => $order->id,
                'sequence' => (int) $stage->sequence * 10,
                'operation_code' => 'STG-'.$stage->sequence,
                'name' => Str::limit((string) $stage->stage_name, 200, ''),
                'work_center_id' => $this->legacyWorkCenter($tenantId),
                'status' => self::StageStatuses[(string) $stage->status] ?? 'pending',
                'planned_run_minutes' => bcmul((string) ($stage->planned_hours ?? '0'), '60', 2),
                'actual_run_minutes' => bcmul((string) ($stage->actual_hours ?? '0'), '60', 2),
                'created_at' => $stage->created_at,
                'updated_at' => $stage->updated_at,
            ]);
            $counts['operations']++;
        }
    }

    /**
     * The manufacturing item of a catalog product, created from the product when missing.
     *
     * @param  array{boms: int, components: int, orders: int, operations: int, items: int}  $counts
     */
    private function item(string $tenantId, string $productId, string $type, array &$counts): ?string
    {
        $key = $tenantId.'|'.$productId;
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }

        $existing = DB::table('mfg_items')->where('tenant_id', $tenantId)->where('product_id', $productId)->value('id');
        if ($existing !== null) {
            return $this->items[$key] = (string) $existing;
        }

        $product = DB::table('products')->where('id', $productId)->first();
        if ($product === null) {
            return null;
        }

        $code = Str::limit((string) ($product->sku ?? 'PRD-'.substr($productId, 0, 8)), 40, '');
        if (DB::table('mfg_items')->where('tenant_id', $tenantId)->where('item_code', $code)->exists()) {
            $code = Str::limit($code, 40, '').'-'.strtoupper(substr(str_replace('-', '', $productId), -8));
        }

        $id = (string) Str::uuid7();
        DB::table('mfg_items')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'item_code' => $code,
            'name' => Str::limit((string) $product->name, 200, ''),
            'item_type' => $type,
            'product_id' => $productId,
            'stock_item_id' => Schema::hasColumn('stock_items', 'product_id') ? DB::table('stock_items')->where('tenant_id', $tenantId)->where('product_id', $productId)->whereNull('deleted_at')->value('id') : null,
            'uom' => Str::limit((string) ($product->unit_measure ?? 'PCS'), 20, ''),
            'standard_cost' => $product->cost_price ?? 0,
            'current_cost' => $product->cost_price ?? 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $counts['items']++;

        return $this->items[$key] = $id;
    }

    /**
     * The tenant's work centre for migrated stages, created when missing.
     */
    private function legacyWorkCenter(string $tenantId): string
    {
        if (isset($this->workCenters[$tenantId])) {
            return $this->workCenters[$tenantId];
        }

        $existing = DB::table('mfg_work_centers')->where('tenant_id', $tenantId)->where('work_center_code', 'LEGACY')->value('id');
        if ($existing === null) {
            $existing = (string) Str::uuid7();
            DB::table('mfg_work_centers')->insert([
                'id' => $existing,
                'tenant_id' => $tenantId,
                'work_center_code' => 'LEGACY',
                'name' => 'Stages of migrated production orders',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->workCenters[$tenantId] = (string) $existing;
    }
}
