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
        Schema::create('pos_terminals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('terminal_code', 50)->index();
            $table->string('name');
            $table->string('hardware_serial', 100)->nullable();
            $table->string('rt_serial_number', 50)->nullable(); // IT Registratore Telematico serial (e.g. 99MEY012345)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'terminal_code']);
        });

        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_terminal_id')->constrained('pos_terminals')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 10, 2)->default(0);
            $table->decimal('closing_cash', 10, 2)->nullable();
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->timestamps();
        });

        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->string('receipt_number', 50)->index();
            $table->decimal('total_net', 15, 2);
            $table->decimal('total_tax', 15, 2);
            $table->decimal('total_gross', 15, 2);
            $table->string('payment_method', 30)->default('cash'); // cash, card, mixed
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_sale_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->string('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });

        Schema::create('rt_fiscal_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pos_sale_id')->unique()->constrained('pos_sales')->cascadeOnDelete();
            $table->string('z_report_number', 50)->nullable();
            $table->string('fiscal_document_number', 50)->nullable()->index();
            $table->string('xml_lot_id', 50)->nullable();
            $table->string('rt_status', 30)->default('transmitted'); // transmitted, error, pending
            $table->timestamp('transmitted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rt_fiscal_receipts');
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_terminals');
    }
};
