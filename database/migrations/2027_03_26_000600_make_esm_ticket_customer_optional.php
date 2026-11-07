<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * An internal ticket has no customer: it used to receive a random identifier to satisfy NOT NULL. The column becomes
     * optional. Existing rows are left as they are, because a stored identifier cannot be told apart from a real contact
     * of a deleted record without the company's review.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE esm_tickets ALTER COLUMN customer_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE esm_tickets SET customer_id = gen_random_uuid() WHERE customer_id IS NULL');
        DB::statement('ALTER TABLE esm_tickets ALTER COLUMN customer_id SET NOT NULL');
    }
};
