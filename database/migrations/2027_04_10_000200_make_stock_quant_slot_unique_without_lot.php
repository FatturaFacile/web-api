<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One stock quant per bin, item and lot, also when there is no lot. The original unique index treated every NULL lot as
 * different, so concurrent movements could create two lot-less quants for the same bin and item.
 *
 * Existing duplicates are merged first: the oldest live quant of the slot keeps the sum of the on-hand and reserved
 * quantities of the live quants, reservation allocations are moved onto it, and the other quants are deleted. Then
 * `UNIQUE NULLS NOT DISTINCT` (PostgreSQL 15 or later) replaces the original index.
 *
 * The audit actor of a stock movement becomes optional: a movement run by the system has no user, and recording an
 * arbitrary member of the company as its author was wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement('LOCK TABLE stock_quants IN SHARE ROW EXCLUSIVE MODE');

                DB::statement(<<<'SQL'
                    CREATE TEMPORARY TABLE stock_quant_merges ON COMMIT DROP AS
                    SELECT id, FIRST_VALUE(id) OVER slot AS keeper_id
                    FROM stock_quants
                    WHERE lot_id IS NULL
                    WINDOW slot AS (PARTITION BY warehouse_bin_id, stock_item_id ORDER BY (deleted_at IS NOT NULL), created_at, id)
                    SQL);

                DB::statement('DELETE FROM stock_quant_merges WHERE keeper_id IN (SELECT keeper_id FROM stock_quant_merges GROUP BY keeper_id HAVING COUNT(*) = 1)');

                DB::statement(<<<'SQL'
                    UPDATE stock_quants AS keeper
                    SET quantity_on_hand = totals.on_hand, quantity_reserved = totals.reserved, updated_at = NOW()
                    FROM (
                        SELECT merge.keeper_id, SUM(quant.quantity_on_hand) AS on_hand, SUM(quant.quantity_reserved) AS reserved
                        FROM stock_quant_merges AS merge
                        JOIN stock_quants AS quant ON quant.id = merge.id
                        WHERE quant.deleted_at IS NULL
                        GROUP BY merge.keeper_id
                    ) AS totals
                    WHERE keeper.id = totals.keeper_id AND keeper.deleted_at IS NULL
                    SQL);

                DB::statement(<<<'SQL'
                    UPDATE stock_reservation_allocations AS allocation
                    SET stock_quant_id = merge.keeper_id
                    FROM stock_quant_merges AS merge
                    WHERE allocation.stock_quant_id = merge.id AND merge.id <> merge.keeper_id
                    SQL);

                DB::statement('DELETE FROM stock_quants WHERE id IN (SELECT id FROM stock_quant_merges WHERE id <> keeper_id)');

                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_quant_slot_nulls ON stock_quants (warehouse_bin_id, stock_item_id, lot_id) NULLS NOT DISTINCT');
                DB::statement('ALTER TABLE stock_quants DROP CONSTRAINT IF EXISTS uq_quant_slot');
                DB::statement('DROP INDEX IF EXISTS uq_quant_slot');
            });
        }

        Schema::table('inventory_audit_entries', function (Blueprint $table): void {
            $table->uuid('actor_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_quant_slot ON stock_quants (warehouse_bin_id, stock_item_id, lot_id)');
            DB::statement('DROP INDEX IF EXISTS uq_quant_slot_nulls');
        }
    }
};
