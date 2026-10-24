<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('loyalty_card_id')->nullable()->constrained('loyalty_cards')->nullOnDelete();
            $table->decimal('cash_amount', 15, 2)->default(0);
            $table->decimal('card_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('completed'); // completed, refunded
            $table->timestamp('refunded_at')->nullable();
        });

        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->decimal('cash_difference', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->dropColumn(['expected_cash', 'cash_difference']);
        });

        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('loyalty_card_id');
            $table->dropColumn(['cash_amount', 'card_amount', 'status', 'refunded_at']);
        });
    }
};
