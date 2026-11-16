<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One stock quant per bin, item and lot, also when there is no lot. PostgreSQL got this with UNIQUE NULLS NOT DISTINCT
 * (migration 2027_04_10_000200); the other engines treat every NULL lot as different, so they get the same rule from a
 * unique index over the lot with NULL replaced by a fixed key. PostgreSQL already has the index and is left alone.
 *
 * Existing duplicates are merged first, like the PostgreSQL migration does: the oldest live quant of the slot keeps the
 * sum of the on-hand and reserved quantities of the live quants, reservation allocations move onto it and the other
 * quants are deleted.
 */
return new class extends Migration
{
    private const string NO_LOT = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql' || Schema::hasIndex('stock_quants', 'uq_quant_slot_nulls')) {
            return;
        }

        $this->mergeDuplicateSlots();

        $lot = "COALESCE(lot_id, '".self::NO_LOT."')";

        match ($driver) {
            'mysql', 'mariadb' => DB::statement("CREATE UNIQUE INDEX uq_quant_slot_nulls ON stock_quants (warehouse_bin_id, stock_item_id, ({$lot}))"),
            default => DB::statement("CREATE UNIQUE INDEX uq_quant_slot_nulls ON stock_quants (warehouse_bin_id, stock_item_id, {$lot})"),
        };
    }

    private function mergeDuplicateSlots(): void
    {
        $slots = DB::table('stock_quants')
            ->whereNull('lot_id')
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'warehouse_bin_id', 'stock_item_id', 'deleted_at', 'quantity_on_hand', 'quantity_reserved'])
            ->groupBy(static fn (object $quant): string => $quant->warehouse_bin_id.'|'.$quant->stock_item_id)
            ->filter(static fn ($quants): bool => $quants->count() > 1);

        foreach ($slots as $quants) {
            $keeper = $quants->first();
            $live = $quants->whereNull('deleted_at');

            if ($keeper->deleted_at === null) {
                DB::table('stock_quants')->where('id', $keeper->id)->update([
                    'quantity_on_hand' => $live->reduce(static fn (string $sum, object $quant): string => bcadd($sum, (string) $quant->quantity_on_hand, 4), '0'),
                    'quantity_reserved' => $live->reduce(static fn (string $sum, object $quant): string => bcadd($sum, (string) $quant->quantity_reserved, 4), '0'),
                    'updated_at' => now(),
                ]);
            }

            $others = $quants->skip(1)->pluck('id')->all();

            DB::table('stock_reservation_allocations')->whereIn('stock_quant_id', $others)->update(['stock_quant_id' => $keeper->id]);
            DB::table('stock_quants')->whereIn('id', $others)->delete();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' && Schema::hasIndex('stock_quants', 'uq_quant_slot_nulls')) {
            DB::statement('DROP INDEX uq_quant_slot_nulls'.(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? ' ON stock_quants' : ''));
        }
    }
};
