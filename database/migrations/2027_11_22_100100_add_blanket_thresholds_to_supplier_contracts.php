<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blanket purchase agreements (Mod 049): the guaranteed minimum spend, the currency and the percentage of the ceiling
     * or of the minimum at which the balance raises an alert. The ceiling stays in total_committed_amount.
     */
    public function up(): void
    {
        Schema::table('supplier_contracts', function (Blueprint $table): void {
            if (! Schema::hasColumn('supplier_contracts', 'minimum_spend_amount')) {
                $table->decimal('minimum_spend_amount', 15, 2)->nullable();
                $table->decimal('alert_threshold_percent', 5, 2)->default(80);
                $table->string('currency', 3)->default('EUR');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_contracts', function (Blueprint $table): void {
            if (Schema::hasColumn('supplier_contracts', 'minimum_spend_amount')) {
                $table->dropColumn(['minimum_spend_amount', 'alert_threshold_percent', 'currency']);
            }
        });
    }
};
