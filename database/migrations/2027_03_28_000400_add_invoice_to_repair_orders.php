<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sales invoice of a repair outside warranty, issued once. Existing repairs stay without one.
     */
    public function up(): void
    {
        Schema::table('repair_orders', function (Blueprint $table): void {
            $table->uuid('invoice_id')->nullable()->index();
            $table->timestamp('invoiced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repair_orders', function (Blueprint $table): void {
            $table->dropColumn(['invoice_id', 'invoiced_at']);
        });
    }
};
