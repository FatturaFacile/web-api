<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The procurement module wrote `fully_received`, `matched` and `submitted` on purchase orders, while the purchasing API
 * of the app wrote `received` and `pending_approval`; the unified vocabulary (App\Enums\PurchaseOrderStatus) keeps the
 * latter. Data only: rows already remapped no longer match, so running it again changes nothing.
 */
return new class extends Migration
{
    /**
     * Legacy value => current value. Kept here (not read from the enum) so the migration never changes.
     *
     * @var array<string, string>
     */
    private const array Remap = [
        'fully_received' => 'received',
        'matched' => 'received',
        'submitted' => 'pending_approval',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            return;
        }

        foreach (self::Remap as $legacy => $current) {
            DB::table('purchase_orders')->where('status', $legacy)->update(['status' => $current]);
        }
    }

    /**
     * Not reversible: a received order cannot be told apart from one the module had marked fully received.
     */
    public function down(): void {}
};
