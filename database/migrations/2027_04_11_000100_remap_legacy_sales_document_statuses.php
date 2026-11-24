<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The invoicing module marked a quote turned into an order as `converted`, while the sales API of the app leaves it
 * `accepted`; the unified vocabulary (App\Enums\QuoteStatus) keeps `accepted` and the link in `converted_to_order_id`.
 * Data only: rows already remapped no longer match, so running it again changes nothing.
 */
return new class extends Migration
{
    /**
     * Legacy value => current value, per table. Kept here (not read from the enum) so the migration never changes.
     *
     * @var array<string, array<string, string>>
     */
    private const array Remap = [
        'quotes' => ['converted' => 'accepted'],
    ];

    public function up(): void
    {
        foreach (self::Remap as $table => $values) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }

            foreach ($values as $legacy => $current) {
                DB::table($table)->where('status', $legacy)->update(['status' => $current]);
            }
        }
    }

    /**
     * Not reversible: an accepted quote that was converted cannot be told apart from one accepted by the customer.
     */
    public function down(): void {}
};
