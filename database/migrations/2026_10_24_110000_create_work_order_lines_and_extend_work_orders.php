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
        Schema::create('work_order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('type', 20)->default('labor'); // labor, material, travel
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('intervention_address')->nullable();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['started_at', 'ended_at', 'intervention_address']);
        });

        Schema::dropIfExists('work_order_lines');
    }
};
