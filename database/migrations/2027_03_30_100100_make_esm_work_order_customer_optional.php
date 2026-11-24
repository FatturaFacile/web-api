<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * An internal work order, such as one dispatched by asset maintenance, has no customer: it used to receive a random
     * identifier to satisfy NOT NULL. The column becomes optional. Existing rows are left as they are, because a stored
     * identifier cannot be told apart from a real contact without the company's review. Dropping NOT NULL is a catalog
     * change only: no row is rewritten.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE esm_work_orders ALTER COLUMN customer_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE esm_work_orders SET customer_id = gen_random_uuid() WHERE customer_id IS NULL');
        DB::statement('ALTER TABLE esm_work_orders ALTER COLUMN customer_id SET NOT NULL');
    }
};
