<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only widens the accepted values, so every existing row stays valid.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payment_batches DROP CONSTRAINT IF EXISTS payment_batches_format_check');
        DB::statement("ALTER TABLE payment_batches ADD CONSTRAINT payment_batches_format_check CHECK (format IN ('sepa_pain_001_it', 'iso_pain_001_ch', 'cbi_riba'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payment_batches DROP CONSTRAINT IF EXISTS payment_batches_format_check');
        DB::statement("ALTER TABLE payment_batches ADD CONSTRAINT payment_batches_format_check CHECK (format IN ('sepa_pain_001_it', 'iso_pain_001_ch')) NOT VALID");
    }
};
